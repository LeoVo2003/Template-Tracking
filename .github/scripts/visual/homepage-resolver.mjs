export function homepageCandidates(requestedUrl) {
  let url;
  try { url = new URL(requestedUrl); } catch { return [String(requestedUrl || '')]; }
  if (!['http:', 'https:'].includes(url.protocol) || url.pathname !== '/' || url.search || url.hash) return [url.toString()];
  const home = new URL(url.toString());
  home.pathname = '/home/';
  return [home.toString(), url.toString()];
}
