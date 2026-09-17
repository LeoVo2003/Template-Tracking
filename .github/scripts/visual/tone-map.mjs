const surface = (canvas) => 'black' === canvas ? 'black' : ('cream' === canvas ? 'cream' : 'white');
export function mapVietnameseTone(result) {
  const primary = result.primary_family || 'neutral', secondary = result.secondary_family || 'neutral', base = surface(result.canvas_family);
  if (result.needs_review || 'neutral' === primary) return 'Cần duyệt';
  if ('pink' === primary) return ['green', 'teal', 'blue'].includes(secondary) ? 'Hồng xanh trắng' : ('black' === base ? 'Hồng đen' : 'Hồng trắng');
  if ('red' === primary) return 'black' === base ? 'Đỏ đen' : ('pink' === secondary ? 'Đỏ hồng' : 'Đỏ trắng');
  if ('orange' === primary) return 'black' === base ? 'Cam đen' : 'Cam trắng';
  if ('brown' === primary) return 'black' === base ? 'Nâu đen' : ('yellow_gold' === secondary ? 'Nâu vàng' : ('cream' === base ? 'Nâu kem' : 'Nâu trắng'));
  if (['green', 'teal', 'blue'].includes(primary)) return 'black' === base ? 'Xanh đen' : ('yellow_gold' === secondary ? 'Xanh vàng' : ('cream' === base ? 'Xanh kem' : 'Xanh trắng'));
  if ('yellow_gold' === primary) return 'black' === base ? 'Vàng đen' : ('cream' === base ? 'Vàng kem sáng' : 'Vàng trắng');
  if ('purple' === primary) return 'black' === base ? 'Tím đen' : ('pink' === secondary ? 'Tím hồng' : 'Tím trắng');
  return 'Cần duyệt';
}
