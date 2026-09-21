/**
 * Build one provider-safe JPEG from the stored full-page capture.
 * Normal pages stay full length; exceptionally tall pages become a 2x2
 * contact sheet so the hero, body and footer remain visible to Vision.
 */
export async function prepareVisionInput(sourceBuffer, options = {}) {
  const { default: sharp } = await import('sharp');
  const metadata = await sharp(sourceBuffer).metadata();
  const sourceWidth = Number(metadata.width || 0);
  const sourceHeight = Number(metadata.height || 0);
  if (!sourceWidth || !sourceHeight) throw new Error('Vision input has no readable dimensions.');

  const maxWidth = Number(options.maxWidth || 1280);
  const extreme = sourceHeight > Number(options.extremeHeight || 12000) || sourceHeight / sourceWidth > Number(options.extremeRatio || 7);
  let buffer;
  let transformation;
  if (!extreme) {
    buffer = await sharp(sourceBuffer).rotate().resize({ width: maxWidth, withoutEnlargement: true }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
    transformation = 'full_page_resize';
  } else {
    const tileWidth = 640;
    const tileHeight = 800;
    const sliceHeight = Math.min(sourceHeight, Math.max(1, Math.round(sourceWidth * tileHeight / tileWidth)));
    const tops = [0, 1 / 3, 2 / 3, 1].map((ratio) => Math.max(0, Math.min(sourceHeight - sliceHeight, Math.round((sourceHeight - sliceHeight) * ratio))));
    const tiles = await Promise.all(tops.map((top) => sharp(sourceBuffer)
      .extract({ left: 0, top, width: sourceWidth, height: sliceHeight })
      .resize(tileWidth, tileHeight, { fit: 'cover', position: 'top' })
      .jpeg({ quality: 82, mozjpeg: true })
      .toBuffer()));
    buffer = await sharp({ create: { width: tileWidth * 2, height: tileHeight * 2, channels: 3, background: '#ffffff' } })
      .composite(tiles.map((input, index) => ({ input, left: (index % 2) * tileWidth, top: Math.floor(index / 2) * tileHeight })))
      .jpeg({ quality: 82, mozjpeg: true })
      .toBuffer();
    transformation = 'four_segment_contact_sheet';
  }
  const output = await sharp(buffer).metadata();
  return {
    buffer,
    metadata: {
      source_width: sourceWidth,
      source_height: sourceHeight,
      source_bytes: sourceBuffer.length,
      input_width: Number(output.width || 0),
      input_height: Number(output.height || 0),
      input_bytes: buffer.length,
      transformation,
    },
  };
}
