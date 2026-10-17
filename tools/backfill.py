#!/usr/bin/env python3
"""Free local subtitle downloader + ZIP packer for Bible Search WordPress plugin.

Requires yt-dlp (pip install -U yt-dlp). Does not download audio or videos.
It works with public video/playlist/channel URLs, subject to YouTube rate limits.
"""
import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

VIDEO_ID = re.compile(r"^([a-zA-Z0-9_-]{11})\.cs(?:-[A-Za-z0-9_-]{1,30})?\.(?:vtt|srt)$", re.I)


def gather_metadata(directory):
    result = {}
    for path in directory.glob("*.info.json"):
        video_id = path.name.split('.', 1)[0]
        if not re.fullmatch(r"[A-Za-z0-9_-]{11}", video_id):
            continue
        try:
            obj = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        date = obj.get("upload_date") or obj.get("release_date") or ""
        published_at = ""
        ts = obj.get("release_timestamp") or obj.get("timestamp")
        if isinstance(ts, (int, float)) and ts > 0:
            # Přesný čas vysílání – plugin podle něj pozná biblickou hodinu (středa) a kázání (neděle).
            published_at = datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
        elif re.fullmatch(r"\d{8}", str(date)):
            try:
                published_at = datetime.strptime(date, "%Y%m%d").replace(tzinfo=timezone.utc).isoformat()
            except ValueError:
                pass
        result[video_id] = {
            "title": str(obj.get("title") or video_id)[:250],
            "published_at": published_at,
            "description": str(obj.get("description") or "")[:4000],
            "availability": str(obj.get("availability") or "public"),
            "live_status": str(obj.get("live_status") or ""),
        }
    return result


def pack_subtitles(directory, destination, part_mb):
    files = []
    for file in sorted(directory.iterdir()):
        if file.is_file() and VIDEO_ID.fullmatch(file.name):
            if 0 < file.stat().st_size <= 2 * 1024 * 1024:
                files.append(file)
    if not files:
        print('Nenalezeny žádné české .vtt/.srt titulky. Některá videa titulky nemají nebo YouTube omezil přístup.')
        return 0

    metadata = gather_metadata(directory)
    destination.mkdir(parents=True, exist_ok=True)
    parts = []
    current = []
    current_size = 0
    max_bytes = int(part_mb * 1024 * 1024)
    used = set()
    for file in files:
        video_id = VIDEO_ID.fullmatch(file.name).group(1)
        if video_id in used:
            continue
        used.add(video_id)
        size = file.stat().st_size
        if current and current_size + size > max_bytes:
            parts.append(current)
            current, current_size = [], 0
        current.append(file)
        current_size += size
    if current:
        parts.append(current)

    for old_archive in destination.glob('biblesearch-titulky-*.zip'):
        old_archive.unlink()

    for i, group in enumerate(parts, 1):
        path = destination / f"biblesearch-titulky-{i:03d}.zip"
        ids = {VIDEO_ID.fullmatch(p.name).group(1) for p in group}
        mini_manifest = {key: metadata[key] for key in ids if key in metadata}
        with ZipFile(path, 'w', compression=ZIP_DEFLATED, compresslevel=6) as archive:
            for file in group:
                archive.write(file, arcname=file.name)
            archive.writestr('manifest.json', json.dumps(mini_manifest, ensure_ascii=False))
        print(f"{path.name} — {len(group)} kázání, {path.stat().st_size / 1024 / 1024:.2f} MiB")
    print(f"Hotovo: {len(files)} souborů, {len(used)} unikátních videí, {len(parts)} ZIP archivů. Importujte každý v administraci WordPressu → Kázání.")
    print('Poznámka: videa bez českých titulků nebudou součástí importu.')
    return len(parts)


def main():
    ap = argparse.ArgumentParser(description='Download Czech YouTube captions and package for WordPress (no video downloads).')
    ap.add_argument('url', help='YouTube kanál / playlist / video URL')
    ap.add_argument('--out', default='biblesearch-export', help='Pracovní adresář a výstupní ZIP')
    ap.add_argument('--part-mb', type=float, default=3.0, help='Uncompressed subtitle payload per ZIP (default 3 MB)')
    ap.add_argument('--pack-only', action='store_true', help='Jen znovu vytvořit ZIPy z již stažených titulků')
    args = ap.parse_args()
    if not (0.2 <= args.part_mb <= 500):
        ap.error('--part-mb musí být v rozmezí 0.2 až 500')
    outdir = Path(args.out).expanduser().resolve()
    subtitles = outdir / 'titulky'
    archives = outdir / 'zip-k-nahrani'
    subtitles.mkdir(parents=True, exist_ok=True)
    if not args.pack_only:
        command = [
            sys.executable, '-m', 'yt_dlp',
            '--skip-download', '--write-subs', '--write-auto-subs',
            '--sub-langs', 'cs,cs-.*', '--sub-format', 'vtt/srt/best',
            '--write-info-json', '--no-write-playlist-metafiles',
            '--ignore-errors', '--no-progress',
            '--sleep-requests', '0.5', '--sleep-interval', '1', '--max-sleep-interval', '3',
            '-o', str(subtitles / '%(id)s.%(ext)s'), args.url,
        ]
        print('Stahuji dostupné titulky a metadata. Žádná videa ani zvuk se nestahují.')
        print('Pokud se objeví 429/403/PO token, postup zastavte, nezkoušejte agresivní paralelní stahování.')
        try:
            result = subprocess.run(command, check=False)
        except KeyboardInterrupt:
            print('\nStahování přerušeno. Již stažené titulky lze zabalit s --pack-only.')
            return 130
        if result.returncode:
            print(f'yt-dlp skončilo s kódem {result.returncode}; vytvořím ZIPy z toho, co se stáhlo.')
    return 0 if pack_subtitles(subtitles, archives, args.part_mb) > 0 else 1


if __name__ == '__main__':
    sys.exit(main())
