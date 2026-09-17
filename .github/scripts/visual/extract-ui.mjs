export async function activateLazyContent(page) {
  await page.evaluate(() => {
    const copy = (element, target, sources) => {
      if (element.getAttribute(target)) return;
      for (const source of sources) {
        const value = element.getAttribute(source);
        if (value) { element.setAttribute(target, value); break; }
      }
    };
    document.querySelectorAll('img').forEach((image) => {
      image.loading = 'eager';
      copy(image, 'src', ['data-src', 'data-lazy-src', 'data-original', 'data-e-src']);
      copy(image, 'srcset', ['data-srcset', 'data-lazy-srcset', 'data-e-srcset']);
    });
    document.querySelectorAll('[data-bg], [data-background-image], [data-lazy-bg]').forEach((element) => {
      const source = element.getAttribute('data-bg') || element.getAttribute('data-background-image') || element.getAttribute('data-lazy-bg');
      if (source && !getComputedStyle(element).backgroundImage.includes('url(')) element.style.backgroundImage = `url("${source}")`;
    });
  });
}

/**
 * Collect computed UI colors from the same page state that will be captured.
 * We deliberately return an area-weighted sample rather than one vote per
 * element: a large section background should outweigh a handful of tiny icons.
 */
export async function collectUiSamples(page) {
  return page.evaluate(() => {
    const pageWidth = Math.max(document.documentElement?.scrollWidth || 0, document.body?.scrollWidth || 0, innerWidth);
    const pageHeight = Math.max(document.documentElement?.scrollHeight || 0, document.body?.scrollHeight || 0, innerHeight);
    const pageArea = Math.max(1, pageWidth * pageHeight);
    const structuralTags = new Set(['HEADER', 'NAV', 'MAIN', 'SECTION', 'ARTICLE', 'FOOTER']);
    const excludedTags = new Set(['IMG', 'PICTURE', 'VIDEO', 'CANVAS', 'IFRAME', 'SOURCE', 'OBJECT', 'EMBED']);
    const roleFor = (element) => {
      const tag = element.tagName;
      if ('BODY' === tag) return 'page';
      if (structuralTags.has(tag)) return 'surface';
      if ('BUTTON' === tag || element.getAttribute('role') === 'button') return 'button';
      if ('A' === tag) return 'link';
      if (/^H[1-4]$/.test(tag)) return 'heading';
      if (['INPUT', 'SELECT', 'TEXTAREA'].includes(tag)) return 'control';
      if ('SVG' === tag) return 'icon';
      if (['P', 'LI', 'LABEL', 'SPAN'].includes(tag)) return 'text';
      return 'container';
    };
    const roleWeight = {
      page: 0.34,
      surface: 1.65,
      button: 2.10,
      control: 1.20,
      container: 0.86,
      link: 0.72,
      heading: 0.36,
      text: 0.16,
      icon: 0.18,
    };
    const parseColor = (value) => {
      const text = String(value || '').trim();
      const rgb = text.match(/rgba?\(\s*([\d.]+)[, ]+\s*([\d.]+)[, ]+\s*([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)/i);
      if (!rgb) return null;
      const alpha = rgb[4] == null ? 1 : (String(rgb[4]).endsWith('%') ? Number.parseFloat(rgb[4]) / 100 : Number(rgb[4]));
      if (!Number.isFinite(alpha) || alpha <= 0.03) return null;
      return { value: text, alpha: Math.max(0, Math.min(1, alpha)) };
    };
    const result = [];
    const add = (color, weight, role, kind, element, areaRatio, structural = false, elementOpacity = 1) => {
      const parsed = parseColor(color);
      if (!parsed || !Number.isFinite(weight) || weight <= 0) return;
      const effectiveOpacity = parsed.alpha * Math.max(0, Math.min(1, Number(elementOpacity) || 0));
      if (effectiveOpacity <= 0.03) return;
      result.push({
        color: parsed.value,
        weight: Number(weight.toFixed(6)),
        opacity: Number(effectiveOpacity.toFixed(3)),
        role,
        kind,
        structural: Boolean(structural),
        area_ratio: Number(Math.max(0, areaRatio).toFixed(6)),
        tag: element?.tagName || '',
      });
    };
    const nodes = [document.body, ...document.querySelectorAll('header,nav,main,section,article,footer,button,a,h1,h2,h3,h4,p,div,li,input,select,textarea,svg')];
    for (const element of nodes.slice(0, 7000)) {
      if (!element || excludedTags.has(element.tagName) || element.closest?.('picture,video,canvas,iframe,object,embed')) continue;
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      const role = roleFor(element);
      const structural = structuralTags.has(element.tagName) || 'BODY' === element.tagName;
      const opacity = Number(style.opacity || 1);
      if (style.display === 'none' || style.visibility === 'hidden' || opacity <= 0.03 || rect.width < 2 || rect.height < 2) continue;
      const left = Math.max(0, rect.left + scrollX);
      const right = Math.min(pageWidth, rect.right + scrollX);
      const top = Math.max(0, rect.top + scrollY);
      const bottom = Math.min(pageHeight, rect.bottom + scrollY);
      const area = Math.max(0, right - left) * Math.max(0, bottom - top);
      const areaRatio = area / pageArea;
      if (areaRatio < 0.000002) continue;
      const hasBackgroundImage = style.backgroundImage && style.backgroundImage !== 'none' && /url\(/i.test(style.backgroundImage);
      const parentBackground = element.parentElement ? getComputedStyle(element.parentElement).backgroundColor : '';
      const ownBackground = parseColor(style.backgroundColor);
      // A background image is media evidence, not UI palette evidence. Keep
      // an explicit overlay color only when it is actually translucent.
      const differentBackground = style.backgroundColor !== parentBackground;
      if (ownBackground && (!hasBackgroundImage || ownBackground.alpha < 0.92) && (differentBackground || structural || 'button' === role || 'control' === role)) {
        const roleFactor = roleWeight[role] || roleWeight.container;
        add(style.backgroundColor, areaRatio * roleFactor, role, 'background', element, areaRatio, structural || 'button' === role, opacity);
      }
      const hasOwnText = [...element.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
      if (hasOwnText || /^H[1-4]$/.test(element.tagName) || ['A', 'BUTTON'].includes(element.tagName)) {
        const textLength = (element.textContent || '').trim().length;
        const textDensity = Math.min(1, Math.max(0.12, textLength / 180));
        const roleFactor = roleWeight[role] || roleWeight.text;
        // Text is intentionally low weight so a long paragraph cannot beat a
        // full-width UI surface. Buttons/headings remain slightly meaningful.
        add(style.color, areaRatio * roleFactor * textDensity, role, 'text', element, areaRatio, false, opacity);
      }
      const borderSides = ['Top', 'Right', 'Bottom', 'Left'];
      const borderWidth = borderSides.reduce((sum, side) => sum + (Number.parseFloat(style[`border${side}Width`]) || 0), 0);
      if (borderWidth > 0) {
        const perimeter = Math.max(1, 2 * (rect.width + rect.height));
        for (const side of borderSides) {
          const sideWidth = Number.parseFloat(style[`border${side}Width`]) || 0;
          if (sideWidth <= 0) continue;
          const borderRatio = Math.min(areaRatio * 0.35, (perimeter * (sideWidth / borderWidth)) / pageArea);
          add(style[`border${side}Color`], borderRatio * (structural ? 0.9 : 0.45), role, 'border', element, borderRatio, structural, opacity);
        }
      }
      if ('SVG' === element.tagName) {
        add(style.fill, areaRatio * roleWeight.icon, role, 'icon', element, areaRatio, false, opacity);
        add(style.stroke, areaRatio * roleWeight.icon, role, 'icon', element, areaRatio, false, opacity);
      }
    }
    // Keep bundle metadata bounded while retaining every large structural
    // surface and the most meaningful controls/text samples.
    return result.sort((a, b) => b.weight - a.weight).slice(0, 1400);
  });
}

export async function hideMediaForPreview(page) {
  await page.addStyleTag({ content: `img,picture,video,canvas,iframe{visibility:hidden!important} *{background-image:none!important} *::before,*::after{background-image:none!important}` });
}
