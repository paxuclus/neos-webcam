# Lala.Webcam

A Neos content element that shows the latest image of a webcam. The camera (or a small computer next to it, e.g. a Raspberry Pi) pushes its images to Neos via HTTP, authenticated with a token stored on the element.

- Images are stored in their own Flow resource collection, so storage and target can be swapped for S3 or similar
- EXIF, XMP and IPTC data, and anything appended after the image, is removed on upload, without needing GD, Imagick or vips
- Pages update the image in place without reloading
- The Neos content cache (and Varnish, if you use `flowpack/varnish`) is flushed for the page on every upload
- Images are deleted once the element is removed from the live workspace

Requires Neos 9.1.

## Installation

```bash
composer require lala/neos-webcam
./flow doctrine:migrate
```

## Usage

1. Add a **Webcam** element to a page.
2. Enter an **upload token** (32 to 255 characters, letters, digits, `-` and `_`; the inspector suggests one) and publish.
3. The inspector now shows the upload URL and a ready to use `curl` command. Only editors who may edit the element and publish to the live workspace see it, since the token lets anyone publish images without review.

The element has to be published and visible before images can be uploaded. Hiding the element (or its page) stops both uploads and the image API, removing it does the same and deletes its images once Neos removes the node for good.

## Uploading images

```bash
curl -fsS \
  -H "X-Webcam-Token: <token>" \
  -F "image=@/path/to/snapshot.jpg" \
  https://example.com/api/webcam/<node-id>
```

`Authorization: Bearer <token>` works as well, but Apache with `mod_proxy_fcgi` drops that header unless configured with `CGIPassAuth On`, which is why the custom header is recommended.

Only JPEG images up to 16384 pixels wide and high are accepted.

| Status | Meaning |
| --- | --- |
| `201` | Image stored. The response contains its URI and dimensions. |
| `400` | No file in the multipart field `image` |
| `403` | Unknown webcam, element not published or hidden, or wrong token |
| `413` | Image is larger than `maximumFileSize` or 16384 pixels |
| `415` | Not a (valid) JPEG |

### Raspberry Pi example

`/usr/local/bin/webcam-upload`:

```bash
#!/bin/sh
set -eu
FILE=$(mktemp --suffix=.jpg)
trap 'rm -f "$FILE"' EXIT

# Replace with whatever produces the image, e.g. rpicam-still or fetching it from an IP camera
rpicam-still --nopreview -o "$FILE"

curl -fsS --retry 3 --max-time 60 \
  -H "X-Webcam-Token: $WEBCAM_TOKEN" \
  -F "image=@$FILE" \
  "$WEBCAM_URL"
```

`/etc/systemd/system/webcam-upload.service`:

```ini
[Unit]
Description=Upload webcam image
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
Environment=WEBCAM_URL=https://example.com/api/webcam/<node-id>
Environment=WEBCAM_TOKEN=<token>
ExecStart=/usr/local/bin/webcam-upload
```

`/etc/systemd/system/webcam-upload.timer`:

```ini
[Unit]
Description=Upload a webcam image every 30 minutes

[Timer]
OnCalendar=*:00,30
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl enable --now webcam-upload.timer
```

A cron entry does the same: `*/30 * * * * WEBCAM_URL=... WEBCAM_TOKEN=... /usr/local/bin/webcam-upload`

## How pages stay up to date

The rendered page contains the URL of the latest image. Image URLs contain the SHA1 of the file, so they never change and can be cached forever.

When the refresh interval of an element is greater than 0, a small script polls `GET /api/webcam/<node-id>` while the page is visible and whenever it becomes visible again. When the image URL changed, the new image is loaded in the background and then swapped in.

## Configuration

```yaml
Lala:
  Webcam:
    # Uploads larger than this are rejected (bytes)
    maximumFileSize: 10485760
    # How many images to keep per webcam. Only the newest one is shown, the previous one stays
    # available for pages and API responses that are still cached with its URL.
    keepSnapshots: 2
    # How long browsers and proxies may cache the "current image" API response (seconds)
    statusMaximumAge: 60
```

Images are stored in the resource collection `lalaWebcam`, which uses the storage `lalaWebcamStorage` (`Data/Persistent/Lala.Webcam/`) and the target `lalaWebcamTarget` (`Web/_Resources/Webcam/`). Override them in your `Settings.yaml` to use a different storage or target.

## Styling

The element renders as

```html
<lala-webcam>
    <img src="..." width="..." height="..." alt="...">
</lala-webcam>
```

It ships with minimal default styles (block element, responsive image) using `:where()`, so any selector of your own overrides them.

To wrap the element in your site's layout, add your mixins to the NodeType or override the prototype `Lala.Webcam:Content.Webcam`.

## Security notes

- The upload token is stored as a plain node property. Every backend user who can open the element can read it in the inspector, only the ready to use upload settings are restricted. Neos 9 can only prevent that with node privileges (`ReadNodePrivilege`/`EditNodePrivilege`) based on subtree tags.
- Flow only masks the `Authorization` header in exception logs. If an exception happens during an upload, `X-Webcam-Token` ends up in `Data/Logs/Exceptions` and in error trackers like Sentry. Scrub the header there, or send the token as `Authorization: Bearer` with `CGIPassAuth On`.
- PHP reads the whole request body before the token is checked. Limit the body size of `/api/webcam` in your web server or proxy (e.g. `LimitRequestBody` in Apache) to slightly above `maximumFileSize`, and consider rate limiting the endpoint.

## License

MIT
