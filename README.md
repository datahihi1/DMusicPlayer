# DMusicPlayer

DMusicPlayer is a lightweight PHP-based music library and audio inspector. It scans a media folder, lets you browse tracks, plays audio files directly in the browser, shows metadata extracted from ffprobe, and supports saving songs for offline playback.

## Features

- Browse a local music library from a configured media directory
- Play supported audio files (`.mp3`, `.m4a`, `.flac`, `.wav`, `.ogg`, `.oga`, `.opus`, `.wma`, `.aac`)
- Open audio from a remote URL as well as local files
- Inspect metadata such as:
  - title
  - artist
  - album
  - year
  - duration
  - bit rate
  - sample rate
  - codec
  - file size and modified date
- Extract embedded cover art using ffmpeg
- Save tracks to browser storage for offline playback
- Works as a simple single-file PHP app with no Composer dependency

## Requirements

- PHP 7.4 or newer
- `ffmpeg` installed and available on `PATH`
- `ffprobe` installed and available on `PATH`
- A web server such as Apache, Nginx, or Laragon

## Installation

1. Copy `DMusicPlayer.php` into your web root or any folder served by your PHP environment.
2. Make sure your music files are in the same folder as the script, or update `MEDIA_DIR` in the file.
3. Start your PHP web server and open the app in a browser.

Example:

```php
define('MEDIA_DIR', __DIR__);
```

This tells the app to serve audio files from the same directory as the script.

## Usage

Open the app in your browser:

```text
http://localhost/DMusicPlayer.php
```

### Local library mode

- The app automatically scans the configured `MEDIA_DIR` for supported audio files.
- Click a track to open its player/info page.
- Use the search box to filter songs by file name or folder.

### Remote URL mode

You can also load a remote file directly:

```text
http://localhost/DMusicPlayer.php?file=https://example.com/song.mp3
```

### Offline mode

On a track detail page:

- Click "Tải về offline"
- The audio file is saved in IndexedDB through the browser
- You can later play it without a network connection

## Security notes

- The application only allows files within the configured media root
- It blocks absolute paths and directory traversal attempts
- Remote URLs are accepted only for supported media extensions

## Supported audio extensions

```text
mp3, m4a, flac, wav, ogg, oga, opus, wma, aac
```

## Project structure

```text
DMusicPlayer/
├── DMusicPlayer.php
├── README.md
└── music files (optional, in same folder or configured media dir)
```

## Troubleshooting

### ffmpeg / ffprobe not found

If metadata or cover art does not appear, make sure both tools are installed and available on your `PATH`.

Example on Linux/macOS:

```bash
ffmpeg -version
ffprobe -version
```

Example on Windows:

```powershell
ffmpeg -version
ffprobe -version
```

### No tracks found

- Confirm the media folder is correctly configured
- Ensure files are under the allowed folder root
- Check that files use a supported extension

## Notes

This app is intentionally lightweight and does not rely on Composer or external PHP libraries. It is designed as a simple local media browser/player for development, personal use, or small internal deployments.
