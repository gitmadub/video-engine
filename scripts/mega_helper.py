#!/usr/bin/env python3

import json
import os
import sys

from mega import Mega


def emit(payload):
    print(json.dumps(payload, ensure_ascii=True))


def info(url):
    data = Mega().get_public_url_info(url)
    emit({
        "name": data.get("name", ""),
        "size": int(data.get("size", 0)),
    })


def download(url, dest_dir, dest_filename):
    os.makedirs(dest_dir, exist_ok=True)
    mega = Mega()
    output_path = os.path.abspath(os.path.join(dest_dir, dest_filename))

    try:
        path = mega.download_url(url, dest_path=dest_dir, dest_filename=dest_filename)
    except PermissionError:
        if not os.path.isfile(output_path):
            raise

        path = output_path

    emit({
        "path": os.path.abspath(path),
        "name": os.path.basename(path),
        "size": int(os.path.getsize(path)),
    })


def main():
    if len(sys.argv) < 3:
        raise SystemExit("Usage: mega_helper.py <info|download> <url> [dest_dir] [dest_filename]")

    command = sys.argv[1].strip().lower()
    url = sys.argv[2].strip()

    if command == "info":
        info(url)
        return

    if command == "download":
        if len(sys.argv) < 5:
            raise SystemExit("Usage: mega_helper.py download <url> <dest_dir> <dest_filename>")

        download(url, sys.argv[3], sys.argv[4])
        return

    raise SystemExit("Unknown command: " + command)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        raise
