#!/usr/bin/env python3
"""CI-only official-image metadata derivative for one common PHP-node mount.

Docker-save archives may also contain OCI indexes/blobs. We consume their
legacy compatibility manifest and emit ONLY a strict legacy load archive, so
no stale OCI index can select the original config. Pure OCI layouts are
rejected explicitly; docker image save supplies the compatibility manifest.
No filesystem layer or official source tag is modified.
"""
import argparse
import copy
import hashlib
import gzip
import io
import json
import os
import pathlib
import re
import subprocess
import sys
import tarfile
import tempfile

VOLUME = "/var/www/html"
HEX = re.compile(r"sha256:[0-9a-f]{64}\Z")
MAX_JSON = 4 * 1024 * 1024


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def changed_config(source):
    result = copy.deepcopy(source)
    volumes = result.get("config", {}).get("Volumes")
    if not isinstance(volumes, dict) or volumes.get(VOLUME) != {}:
        raise ValueError("official image must declare the expected empty child-volume metadata")
    del volumes[VOLUME]
    return result


def safe_name(name):
    if not isinstance(name, str) or not name or "\\" in name or "\0" in name:
        raise ValueError("invalid archive member name")
    path = pathlib.PurePosixPath(name)
    if path.is_absolute() or any(p in ("", ".", "..") for p in name.split("/")):
        raise ValueError("unsafe archive member name")
    return name


def transform(source_path, output_path, derived_ref, expected_source_id):
    if not HEX.fullmatch(expected_source_id):
        raise ValueError("invalid expected source image ID")
    if pathlib.Path(source_path).resolve() == pathlib.Path(output_path).resolve():
        raise ValueError("source archive cannot be overwritten")
    with tarfile.open(source_path, "r:*") as source:
        members = {}
        for member in source.getmembers():
            if member.name in members:
                raise ValueError("duplicate archive member")
            members[member.name] = member

        def regular(name):
            safe_name(name)
            member = members.get(name)
            if member is None or not member.isfile():
                raise ValueError("required regular archive member missing")
            return member

        def document(name):
            member = regular(name)
            if member.size > MAX_JSON:
                raise ValueError("image metadata exceeds bounded JSON allowance")
            return source.extractfile(member).read()

        if "manifest.json" not in members:
            raise ValueError("Docker-save legacy compatibility manifest required; pure OCI layout unsupported")
        manifest = json.loads(document("manifest.json"))
        if not isinstance(manifest, list) or len(manifest) != 1:
            raise ValueError("exactly one saved source image is required")
        item = manifest[0]
        config_bytes = document(item["Config"])
        source_id = "sha256:" + hashlib.sha256(config_bytes).hexdigest()
        if source_id != expected_source_id:
            raise ValueError("source config digest does not match inspected official image")
        original = json.loads(config_bytes)
        updated = changed_config(original)
        layers = item.get("Layers")
        diff_ids = original.get("rootfs", {}).get("diff_ids")
        if not isinstance(layers, list) or not isinstance(diff_ids, list) or len(layers) != len(diff_ids):
            raise ValueError("layer inventory differs from source rootfs")
        layer_info = {}
        for name, expected in zip(layers, diff_ids):
            member = regular(name)
            digest = hashlib.sha256()
            size = 0
            with source.extractfile(member) as raw:
                compressed = raw.read(2) == b"\x1f\x8b"
            with source.extractfile(member) as raw:
                stream = gzip.GzipFile(fileobj=raw) if compressed else raw
                for block in iter(lambda: stream.read(1024 * 1024), b""):
                    digest.update(block)
                    size += len(block)
                if compressed:
                    stream.close()
            if "sha256:" + digest.hexdigest() != expected:
                raise ValueError("saved layer bytes do not match source rootfs diff ID")
            layer_info[name] = (size, compressed)
        encoded = canonical(updated)
        derived_id = "sha256:" + hashlib.sha256(encoded).hexdigest()
        config_name = derived_id[7:] + ".json"
        if config_name in layers or "manifest.json" in layers:
            raise ValueError("archive metadata/layer name collision")
        result_manifest = [{"Config": config_name, "RepoTags": [derived_ref], "Layers": layers}]
        with tarfile.open(output_path, "w", format=tarfile.PAX_FORMAT) as output:
            for name, data in (("manifest.json", canonical(result_manifest)), (config_name, encoded)):
                info = tarfile.TarInfo(name)
                info.size = len(data)
                info.mode = 0o644
                output.addfile(info, io.BytesIO(data))
            for name in dict.fromkeys(layers):
                original_member = regular(name)
                info = tarfile.TarInfo(name)
                info.size, compressed = layer_info[name]
                info.mode = 0o644
                with source.extractfile(original_member) as raw:
                    stream = gzip.GzipFile(fileobj=raw) if compressed else raw
                    output.addfile(info, stream)
                    if compressed:
                        stream.close()
    return derived_id


def verify_inspection(source, derived, expected_id=None):
    expected = copy.deepcopy(source["Config"])
    volumes = expected.get("Volumes")
    if not isinstance(volumes, dict) or volumes.get(VOLUME) != {}:
        raise ValueError("source inspection lacks expected volume")
    del volumes[VOLUME]
    actual = copy.deepcopy(derived.get("Config", {}))
    if not expected["Volumes"] and actual.get("Volumes") in (None, {}):
        actual["Volumes"] = {}
    if actual != expected or derived.get("RootFS") != source.get("RootFS"):
        raise ValueError("derived runtime config or filesystem layers differ beyond child volume")
    for key in ("Architecture", "Os", "OsVersion", "Variant", "Created", "Author", "Comment"):
        if source.get(key) != derived.get(key):
            raise ValueError("derived platform/provenance metadata differs: " + key)
    if not HEX.fullmatch(derived.get("Id", "")) or expected_id is not None and derived["Id"] != expected_id:
        raise ValueError("derived image ID mismatch")


def docker(*args, check=True):
    return subprocess.run(["docker", *args], check=check, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=300)


def inspect(ref, required=True):
    result = docker("image", "inspect", ref, check=False)
    if result.returncode:
        if required:
            raise ValueError("source image must be present locally before preparing node metadata: " + ref)
        return None
    documents = json.loads(result.stdout)
    if not isinstance(documents, list) or len(documents) != 1:
        raise ValueError("expected one inspected image")
    return documents[0]


def prepare(source_ref):
    source = inspect(source_ref, required=False)
    if source is None:
        if os.environ.get("WPAI_CI_PULL_POLICY") == "never":
            raise ValueError("verified source image missing under no-pull policy")
        docker("image", "pull", source_ref)
        source = inspect(source_ref)
    source_id = source["Id"]
    if not HEX.fullmatch(source_id):
        raise ValueError("invalid inspected source ID")
    derived_ref = "wpai-admin13-node:" + source_id[7:]
    existing = inspect(derived_ref, required=False)
    if existing is None:
        with tempfile.TemporaryDirectory(prefix="wpai-admin13-image-") as directory:
            saved = pathlib.Path(directory) / "source.tar"
            loaded = pathlib.Path(directory) / "derived.tar"
            docker("image", "save", "--output", str(saved), source_ref)
            expected_id = transform(saved, loaded, derived_ref, source_id)
            docker("image", "load", "--input", str(loaded))
            existing = inspect(derived_ref)
            verify_inspection(source, existing, expected_id)
    else:
        verify_inspection(source, existing)
    if inspect(source_ref)["Id"] != source_id:
        raise ValueError("official source tag changed while deriving metadata")
    print(json.dumps({"source_ref": source_ref, "source_id": source_id,
                      "derived_ref": derived_ref, "derived_id": existing["Id"],
                      "only_removed_volume": VOLUME, "rootfs_unchanged": True}), file=sys.stderr)
    return derived_ref


def check_compose(path):
    document = json.loads(pathlib.Path(path).read_text())
    services = document["services"]
    for name, variable in (("wordpress", "apache"), ("cli", "cli")):
        service = services[name]
        if service.get("pull_policy") != "never":
            raise ValueError("verified derived node image must never be pulled: " + name)
        mounts = service.get("volumes", [])
        common = [m for m in mounts if m.get("target") == "/var/www"]
        if len(common) != 1 or common[0].get("type") != "volume":
            raise ValueError("one common node volume mount is required: " + name)
        if any(m.get("target", "").rstrip("/") == "/var/www/html" for m in mounts):
            raise ValueError("child mount would split public/private publication: " + name)
        if not service.get("image", "").startswith("wpai-admin13-node:"):
            raise ValueError("metadata-derived node image required: " + variable)
    if services["wordpress"]["volumes"] != services["cli"]["volumes"]:
        raise ValueError("Apache and CLI must share the same mount view")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="action", required=True)
    create = sub.add_parser("prepare")
    create.add_argument("source_ref")
    validate = sub.add_parser("check-compose")
    validate.add_argument("path")
    args = parser.parse_args()
    if args.action == "prepare":
        print(prepare(args.source_ref))
    else:
        check_compose(args.path)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError, tarfile.TarError) as error:
        print("ERROR: completion node metadata: " + str(error), file=sys.stderr)
        sys.exit(1)
