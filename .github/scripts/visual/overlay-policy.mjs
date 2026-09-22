const BACKDROP_TYPES = new Set(['modal_backdrop', 'pum_backdrop', 'mfp_backdrop']);
const MODAL_TYPES = new Set(['elementor_popup', 'bootstrap_modal', 'pum_popup', 'mfp_popup', 'aria_modal']);

/**
 * Decide whether a visible element has enough modal evidence to be removed.
 * This intentionally rejects ordinary fixed/sticky UI unless it is both large
 * and exposes a close control.
 */
export function classifyOverlayEvidence(descriptor = {}) {
  const visible = Boolean(descriptor.visible);
  const type = String(descriptor.type || 'generic_overlay');
  const coverage = Math.max(0, Math.min(1, Number(descriptor.coverage) || 0));
  const zIndex = Number(descriptor.z_index) || 0;
  const fixed = Boolean(descriptor.fixed);
  const sticky = Boolean(descriptor.sticky);
  const closeControl = Boolean(descriptor.has_close_control);
  const ariaModal = Boolean(descriptor.aria_modal);
  const roleDialog = Boolean(descriptor.role_dialog);

  if (!visible || sticky) return { obstructive: false, type, reason: visible ? 'sticky_ui' : 'not_visible' };

  if (BACKDROP_TYPES.has(type)) {
    const obstructive = fixed && coverage >= 0.18;
    return { obstructive, type, reason: obstructive ? 'verified_backdrop' : 'weak_backdrop_evidence' };
  }

  if (ariaModal) return { obstructive: true, type, reason: 'aria_modal' };

  if (MODAL_TYPES.has(type)) {
    const obstructive = fixed || closeControl || coverage >= 0.12 || zIndex >= 100;
    return { obstructive, type, reason: obstructive ? 'known_modal' : 'weak_modal_evidence' };
  }

  if (roleDialog) {
    const obstructive = fixed || closeControl || coverage >= 0.08 || zIndex >= 100;
    return { obstructive, type: 'aria_dialog', reason: obstructive ? 'dialog_evidence' : 'weak_dialog_evidence' };
  }

  const obstructive = fixed && zIndex >= 1000 && coverage >= 0.15 && closeControl;
  return { obstructive, type, reason: obstructive ? 'fixed_high_coverage_with_close' : 'ordinary_page_ui' };
}
