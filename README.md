# CubeDigital Image Replacer

WordPress plugin that replaces a Media Library image with a small Replicate image-to-image variation. The attachment ID, file name, and URL stay the same. Thumbnails are regenerated. The first file is kept so it can be restored.

## Install

Clone this repo into `wp-content/plugins`, then activate **CubeDigital Image Replacer**.

```bash
cd wp-content/plugins
git clone <repo-url> cubedigital-image-replacer
```

To update a checkout already on `main`:

```bash
git pull origin main
```

Requires WordPress 6.2+, PHP 7.4+, and outbound HTTPS. Imagick is preferred. GD is used if Imagick cannot write the file.

## Setup

1. Open **Settings → AI Image Replacer**.
2. Paste a token from [replicate.com/account/api-tokens](https://replicate.com/account/api-tokens) and save.
3. Click **Test connection**.
4. Leave **Variation** at `5` for about a 5% change. Raise it to 10–15 if a result looks identical.
5. Leave **Saturation** at `100` for no extra change. `80` mutes colour, `120` makes it richer. This is applied after the AI step.

The token is stored in WordPress options and is not written into the page after it is saved.

## Use

In the Media Library list, open the row menu and choose **AI variation**. The same action is on the attachment edit screen and in the media modal.

**Restore original** copies the first backed-up file back over the live file and regenerates thumbnails. Later variations are always made from that original, so repeated runs do not drift further away.

Each run spends Replicate credits. The default model is `stability-ai/sdxl` with watermarking off and the refiner off, so the result stays close to the photo.

## Limits

- The file name does not change, so a browser or CDN can keep showing the old image until that cache is cleared.
- Animated GIFs are skipped.
- Files offloaded to S3 or another remote bucket are not updated.
- A site does not need a public image URL. The plugin sends the file to Replicate itself.
