/* global ImportEverything, wp */
(() => {
  'use strict';
  const { __, sprintf } = wp.i18n;
  /** @param {string} text */
  const t = (text) => __(text, 'import-everything');
  const root = document.getElementById('iex-app');
  if (!root) return;
  /** @type {IexState} */
  const state = {
    tab: 'import', step: 1, source: null, busy: false, error: '', info: '', filter: '', preview: null,
    job: null, running: false, paused: false, target: null, confirmed: false, jobs: null, jobsMeta: null, jobsError: '', feeds: null, feedsError: '', feedId: null, feedName: '', editing: null, editName: '', editUrl: '', supportFields: false,
    settings: { type: 'post', status: 'draft', identity: '', duplicates: 'skip', mapping: { title: '', content: '', excerpt: '', image: '', unique: '', categories: '', tags: '', actors: '' }, custom: [] }
  };

  /**
   * @template {keyof HTMLElementTagNameMap} K
   * @param {K} tag
   * @param {IexAttrs} attrs
   * @param {...IexChild} children
   * @returns {HTMLElementTagNameMap[K]}
   */
  function node(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
      else if (key === 'class') el.className = String(value);
      else if (key === 'text') el.textContent = String(value);
      else if (value !== false && value != null) el.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of children.flat()) if (child != null && typeof child !== 'boolean') el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    return el;
  }
  /** @param {string} label @param {() => unknown} action @param {string} kind @param {IexAttrs} attrs */
  const button = (label, action, kind = 'secondary', attrs = {}) => node('button', { type: 'button', class: `iex-btn iex-${kind}`, onclick: action, disabled: state.busy, ...attrs }, label);
  /** @param {string} content @param {string} className */
  const text = (content, className = '') => node('p', { class: className }, content);
  /** @param {string} label @param {HTMLElement} control @param {string} [hint] */
  const field = (label, control, hint) => node('div', { class: 'iex-field' }, node('label', { for: control.id }, label), control, hint && text(hint, 'iex-hint'));
  /** @param {string} id @param {IexOption[]} options @param {string} value @param {(value: string) => void} change @param {IexAttrs} attrs */
  function select(id, options, value, change, attrs = {}) {
    const el = node('select', { id, onchange: () => change(el.value), ...attrs });
    el.classList.add('iex-select');
    for (const option of options) el.append(node('option', { value: option.value }, option.label));
    el.value = value || '';
    return el;
  }
  /** @param {string} id @param {string} value @param {(value: string) => void} change @param {IexAttrs} attrs */
  function input(id, value, change, attrs = {}) {
    const el = node('input', { id, type: 'text', value, oninput: () => change(el.value), ...attrs });
    return el;
  }
  function changed() {
    state.preview = null; state.confirmed = false;
    const importButton = document.getElementById('iex-import');
    if (importButton instanceof HTMLButtonElement) importButton.disabled = true;
    const confirmation = document.getElementById('iex-confirm');
    if (confirmation instanceof HTMLInputElement) { confirmation.checked = false; confirmation.disabled = true; }
    const preview = document.getElementById('iex-preview');
    if (preview) preview.replaceChildren(node('h2', {}, t('Preview needs refreshing')), text(t('Your mapping or settings changed. Generate a new preview before importing.'), 'iex-hint'));
  }
  function source() {
    if (!state.source) throw new Error(t('Load an XML source first.'));
    return state.source;
  }
  function job() {
    if (!state.job) throw new Error(t('Start an import first.'));
    return state.job;
  }
  /** @param {unknown} error */
  function errorMessage(error) { return error instanceof Error ? error.message : String(error); }
  /** Human name of each endpoint operation, so a failure names the action that could not complete. */
  function requestLabel(operation) {
    const labels = {
      source: t('Fetch & analyze'), analyze: t('Re-analyze'), preview: t('Preview'), start: t('Start import'),
      batch: t('Import batch'), clear: t('Clear session'), support: t('Support report'), feeds: t('Saved feeds'),
      feed_save: t('Save feed'), feed_open: t('Open saved feed'), feed_update: t('Update saved feed'), feed_run: t('Run saved feed'),
      feed_schedule: t('Schedule feed'), feed_posts: t('Count feed posts'), feed_clear: t('Delete feed posts'),
      feed_delete: t('Delete feed'), jobs: t('Import history'), batch_size: t('Records per batch'),
    };
    return labels[operation] || String(operation);
  }
  /**
   * Everything a failed request can be told apart by: which request it was, the exact URL it went to, whether
   * the page and that address agree on https, and the HTTP status when the browser got one back.
   *
   * @param {string} operation Endpoint operation the request carried.
   * @param {string} ajax      WordPress's admin-ajax.php URL as this page sees it.
   * @param {number} [status]  HTTP status, when a response arrived.
   * @returns {string}
   */
  function requestDetail(operation, ajax, status) {
    const url = String(ajax || '(no address)');
    const page = window.location.protocol.replace(':', '') || 'unknown';
    const target = (/^([a-z][a-z0-9+.-]*):/i.exec(url) || [])[1] || 'unknown';
    const head = status
      ? sprintf(t('Request “%1$s” to %2$s returned HTTP %3$s.'), requestLabel(operation), url, String(status))
      : sprintf(t('Request “%1$s” to %2$s returned no HTTP status.'), requestLabel(operation), url);
    return `${head} ${sprintf(t('This page is %1$s; that address is %2$s.'), page, target)}`;
  }
  /**
   * Why a request the browser could not even send is reported in the plugin's own words.
   *
   * `fetch()` raises a TypeError ("Failed to fetch") for mixed content, a blocked or unreachable
   * admin-ajax.php, and an offline tab alike — none of which is about the feed being imported.
   *
   * @param {string} operation Endpoint operation that was being sent.
   * @param {string} ajax      WordPress's admin-ajax.php URL as this page sees it.
   * @returns {string}
   */
  function networkMessage(operation, ajax) {
    const detail = requestDetail(operation, ajax, 0);
    return window.location.protocol === 'https:' && /^http:/i.test(String(ajax || ''))
      ? `${detail} ${t('The browser blocks an https page calling an http admin-ajax.php as mixed content, so set the site URL to https in Settings → General and reload.')}`
      : `${detail} ${t('The browser could not reach that address: the site may have stopped, a security plugin or browser extension may be blocking admin-ajax.php, or the connection dropped. This is a network problem, not a feed error.')}`;
  }
  /**
   * Why a response that arrived but carried no usable plugin answer is reported with its status.
   *
   * @param {string} operation Endpoint operation that was sent.
   * @param {number} status    HTTP status the response carried.
   * @returns {string}
   */
  function unreadableMessage(operation, status) {
    return `${requestDetail(operation, ImportEverything.ajax, status)} ${t('The reply was not JSON, so the plugin’s answer never arrived: check the PHP error log and server limits, disable a security plugin that filters admin-ajax.php, or reload an expired login.')}`;
  }
  /**
   * Every field this feed has, plus one entry per extra value of a repeated line: `field#2` reads the second
   * value of that record, which is how a feed that sends several screenshots lets you pick one.
   */
  const options = () => {
    const fields = source().schema.fields;
    const literal = new Set(fields.map((f) => f.path));
    return [{ value: '', label: t('— Choose an XML field —') }, ...fields.flatMap((f) => {
      const rows = [{ value: f.path, label: f.path }];
      // An older schema carries no value count, so only a schema that reports one offers positions. A path the
      // feed already has — a JSON key ending in `#2` — is never offered twice.
      const count = Math.min(f.values || 0, 5);
      for (let i = 2; i <= count; i += 1) {
        const path = `${f.path}#${i}`;
        if (!literal.has(path)) rows.push({ value: path, label: `${f.path} · ${sprintf(t('value %s'), i)}` });
      }
      return rows;
    })];
  };
  /**
   * Whether any part of this mapping points at a field that holds no value anywhere in the feed.
   *
   * The field's sample is the schema's own evidence that it is empty in every record. A joined value needs both of
   * its parts, so one empty part is enough. Auto-mapping skips these fields; a hand-picked one, or a saved mapping
   * made before the field went empty, can still land on one.
   *
   * A fixed value is never empty, and a template is empty only when every field it reads is empty everywhere:
   * text around a missing placeholder still writes something.
   *
   * @param {string} value
   * @returns {boolean}
   */
  function emptyFieldPath(value) {
    const fields = (source().schema || {}).fields || [];
    const [kind, text] = literalParts(value);
    if ('const' === kind) return false;
    if ('tpl' === kind) {
      const names = [...text.matchAll(/\{([^{}]+)\}/g)].map((match) => match[1]);
      return names.length > 0 && names.every((name) => {
        const field = fields.find((f) => f.path === name);
        return !!field && '' === (field.sample || '');
      });
    }
    const parts = pathParts(value);
    if (!parts.length) return false;
    return parts.some((part) => {
      const field = fields.find((f) => f.path === part);
      return !!field && '' === (field.sample || '');
    });
  }
  /** @param {string} id @param {string} value @param {(value: string) => void} change */
  function mappingSelect(id, value, change) {
    const el = select(id, options(), value, (v) => { change(v); changed(); });
    el.addEventListener('focus', () => { state.target = el; });
    el.addEventListener('dragover', (event) => event.preventDefault());
    el.addEventListener('drop', (event) => {
      event.preventDefault();
      const path = event.dataTransfer?.getData('text/plain') || '';
      if (source().schema.fields.some((f) => f.path === path)) {
        el.value = path; el.dispatchEvent(new Event('change'));
      }
    });
    return el;
  }
  /** Two feed lines can complete one URL: a base in one field and the file name in another. */
  const JOIN = ' + ';
  /** The mapping targets that are taxonomies, so they are handled as term names rather than a plain value. */
  const TERM_TARGETS = ['categories', 'tags', 'actors'];
  /**
   * The field names each term target may arrive under, most preferred first — one map for the suggestions and for
   * the preset's "this type cannot store it" note, so the two cannot disagree.
   *
   * Actors is WP-Script's performer taxonomy, and the feeds that carry performers name the element `actors`,
   * `performers`, `pornstars` or a bare `model` (NATS's own field for the performer) — `model` was missing, which
   * left Actors empty on exactly the feeds the WP-Script preset exists for.
   */
  const TERM_NAMES = {
    categories: ['category', 'categories', 'cat'],
    tags: ['tags', 'tag', 'keywords'],
    actors: ['actors', 'actor', 'pornstars', 'pornstar', 'performers', 'models', 'model'],
  };
  /**
   * The field names the featured image may arrive under, most preferred first.
   *
   * A source whose poster is named its own way passes its own list to autoMap() — Tube Corporate's
   * `main_screenshot` is the case that needs one, because nothing in it answers to `thumb` or `screen` exactly.
   */
  const IMAGE_NAMES = ['thumbnail', 'thumb', 'screen', 'preview', 'image', 'poster', 'still'];
  /**
   * The term targets a post type registers, from the server's own taxonomy map.
   *
   * A mapping the type cannot store is refused by the server rather than dropped, so the suggestions have to know
   * the type before they offer one. A page served without the map (an older build) keeps offering every target
   * rather than none.
   *
   * @param {string} type
   * @returns {string[]}
   */
  const termTargetsFor = (type) => {
    const map = ImportEverything.taxonomies || {};
    return map[type] || (Object.keys(map).length ? [] : TERM_TARGETS);
  };
  /** @param {unknown} value @returns {string[]} */
  const pathParts = (value) => String(value || '').split(JOIN).map((part) => part.trim()).filter(Boolean);
  /** @param {string} base @param {string} suffix @returns {string} */
  const pathJoin = (base, suffix) => (base && suffix ? `${base}${JOIN}${suffix}` : base || suffix || '');
  /**
   * The prefixes a mapping value carries when it is text rather than a feed field: `const:` text written for
   * every record, or `tpl:` text with `{field}` placeholders. The server validates both and stores the same
   * string, so a saved feed and a mapping template keep the value without a second shape.
   */
  const LITERAL = /^(const|tpl):/;
  /** @param {unknown} value @returns {[string, string]} The kind (`const`/`tpl`) and its text, or '' for a field. */
  const literalParts = (value) => {
    const raw = String(value || '');
    const match = LITERAL.exec(raw);
    return match ? [match[1], raw.slice(match[0].length)] : ['', ''];
  };
  /** The value a typed box stores: braces make it a template, plain text a fixed value. */
  const literalValue = (text) => {
    const clean = String(text || '').trim();
    if (!clean) return '';
    return /\{[^{}]+\}/.test(clean) ? `tpl:${clean}` : `const:${clean}`;
  };
  /**
   * Whether a template can read this feed: every placeholder has to be a field it has, which is the check the
   * server makes, so a saved mapping that no longer fits is dropped here instead of failing at preview.
   *
   * @param {string} text
   * @param {Set<string>} known
   * @returns {boolean}
   */
  const templateFits = (text, known) => {
    const names = [...String(text).matchAll(/\{([^{}]+)\}/g)].map((match) => match[1]);
    return names.length > 0 && names.every((name) => known.has(name));
  };
  /**
   * The text a saved mapping or template carries, when it carries text at all. A fixed value is kept as typed,
   * a template only while this feed still has every field it reads, and null means the value is a feed path.
   *
   * @param {unknown} value
   * @param {Set<string>} known Field paths of this feed.
   * @returns {string | null}
   */
  const keptLiteral = (value, known) => {
    const [kind, text] = literalParts(value);
    if (!kind) return null;
    return 'const' === kind || templateFits(text, known) ? `${kind}:${text}` : '';
  };
  /**
   * A mapping that can read one value out of two feed lines, e.g. `screen_url` + `clips/clip/screens/screen`.
   *
   * NATS feeds keep a base directory and a relative file name in separate elements, so only the two joined
   * together resolve — the video and its poster are 404s otherwise. The second dropdown is empty by default
   * and the server revalidates both parts against the schema it discovered.
   *
   * @param {string} id
   * @param {string} value
   * @param {(value: string) => void} change
   */
  function mappingPair(id, value, change) {
    const [base = '', suffix = ''] = pathParts(value);
    let head = base;
    let tail = suffix;
    const push = () => change(pathJoin(head, tail));
    const primary = mappingSelect(id, head, (v) => { head = v; push(); });
    const secondary = mappingSelect(`${id}-append`, tail, (v) => { tail = v; push(); });
    secondary.options[0].textContent = t('— Append nothing —');
    secondary.setAttribute('aria-label', t('Append a second XML field'));
    secondary.title = t('Feeds that split a URL across two lines need both: the field holding the base first, the file name here.');
    return node('div', { class: 'iex-pair' }, primary,
      node('div', { class: 'iex-pair-append' }, node('label', { for: secondary.id, class: 'iex-pair-label' }, t('Then append')), secondary));
  }

  /**
   * The picker for a term target or custom row, with a box under it for text written for every record.
   *
   * The box holds a fixed value (`Uncategorized`) or a template (`{site_name} - {title}`); the stored mapping
   * value carries the `const:`/`tpl:` prefix, so the server validates it exactly as it validates a feed path and
   * a saved feed keeps it. While the box has text it is the value, so the picker is disabled; clearing the box
   * returns to the field the picker shows, and leaving the box re-renders so the controls a single value cannot
   * use follow. Typing never re-renders: the box would lose focus after one character.
   *
   * @param {string} id
   * @param {string} value
   * @param {(value: string) => void} change Setter for the box, called on each keystroke without a re-render.
   * @param {HTMLElement} picker The field picker this box replaces while it has text.
   * @param {string} [placeholder]
   * @returns {HTMLElement}
   */
  function literalControl(id, value, change, picker, placeholder) {
    const fieldPicker = picker.querySelector('select');
    const box = input(`${id}-literal`, literalParts(value)[1], (typed) => {
      const literal = literalValue(typed);
      sync();
      change(literal || (fieldPicker ? fieldPicker.value : ''));
    }, {
      placeholder: placeholder || t('Uncategorized, or {site_name} - {title}'),
      'aria-label': t('Fixed value or template'),
      onchange: () => render(),
    });
    /** Disable the picker while the box is the value, so a row cannot show two answers at once. */
    function sync() {
      const typed = box.value.trim() !== '';
      // A term picker is the select itself, while a two-line picker is a wrapper, so the picker is checked too.
      const controls = [picker, ...picker.querySelectorAll('select, input')].filter((el) => el.matches('select, input'));
      for (const el of controls) el.disabled = typed;
    }
    sync();
    return node('div', { class: 'iex-literal' }, picker, box);
  }

  /**
   * @template {keyof IexResponses} O
   * @param {O} operation
   * @param {object} payload
   * @param {File} [file]
   * @returns {Promise<IexResponses[O]>}
   */
  async function request(operation, payload = {}, file) {
    const body = new FormData();
    body.set('action', 'iex_request'); body.set('nonce', ImportEverything.nonce);
    body.set('operation', operation); body.set('payload', JSON.stringify(payload));
    if (file) body.set('xml', file);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 90000);
    try {
      const response = await fetch(ImportEverything.ajax, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
      let result;
      try { result = await response.json(); } catch { throw new Error(unreadableMessage(operation, response.status)); }
      if (!response.ok || !result.success) {
        /** @type {Error & { retryAfter?: number }} */
        const failure = new Error(result.data?.message || `${requestDetail(operation, ImportEverything.ajax, response.status)} ${t('The request was rejected without a message. Refresh the page if your login or security token expired.')}`);
        if (typeof result.data?.retry_after === 'number') failure.retryAfter = result.data.retry_after;
        throw failure;
      }
      return result.data;
    } catch (error) {
      if (error instanceof Error && error.name === 'AbortError') throw new Error(`${requestDetail(operation, ImportEverything.ajax, 0)} ${t('The request timed out after 90 seconds. If importing, resume to reconcile the last completed batch.')}`);
      if (error instanceof TypeError) throw new Error(networkMessage(operation, ImportEverything.ajax));
      throw error;
    } finally { clearTimeout(timeout); }
  }
  /** @param {() => Promise<unknown>} action */
  async function work(action) {
    state.busy = true; state.error = ''; state.info = ''; render();
    try { await action(); } catch (error) { state.error = errorMessage(error); }
    finally { state.busy = false; render(); }
  }
  /**
   * Names a feed path can be matched by, so prefixed and attribute-only fields are not missed:
   * media:thumbnail/@url -> thumbnail/@url, itunes:image/@href -> image/@href, content:encoded -> content.
   * @param {string} path
   * @returns {string[]}
   */
  function pathNames(path) {
    const full = path.toLowerCase();
    const parts = full.split('/');
    const last = parts[parts.length - 1];
    const attribute = last.startsWith('@') ? last : '';
    if (!attribute) {
      const colon = last.indexOf(':');
      return [full, colon === -1 ? last : last.slice(colon + 1), colon === -1 ? '' : last.slice(0, colon)].filter(Boolean);
    }
    // An attribute path is '<element>/@<attr>'; its value is the attribute, never the element text.
    const element = parts[parts.length - 2] || '';
    if (!element) return [full];
    const colon = element.indexOf(':');
    const local = colon === -1 ? element : element.slice(colon + 1);
    return [full, `${local}/${attribute}`, colon === -1 ? '' : element.slice(0, colon)].filter(Boolean);
  }
  /** A field whose sample is an absolute directory URL: the kind of element that holds a URL's first line. */
  const isBase = (field) => !!field.sample && /^https?:\/\/\S+\/$/.test(field.sample);
  /**
   * The directory field a relative value belongs to, or an empty string when nothing matches by name.
   *
   * The value's own path segments are matched against each directory field's name after stripping a `url`
   * suffix (`screen_url` -> `screen`), and the latest and longest match wins — which is why `clip_url` loses
   * to `screen_url` for `clips/clip/screens/screen`. `loose` also accepts the only directory field there is.
   *
   * @param {string} path Relative field path.
   * @param {IexField[]} bases Directory-URL fields of this schema.
   * @param {boolean} [loose]
   * @returns {string}
   */
  const baseFor = (path, bases, loose = false) => {
    const segments = String(path).toLowerCase().split('/').filter((part) => part && !part.startsWith('@'));
    let best = '';
    let score = 0;
    for (const base of bases) {
      const token = (pathNames(base.path).pop() || '').replace(/url$/i, '').replace(/[^a-z0-9]/gi, '').toLowerCase();
      if (!token) continue;
      segments.forEach((segment, index) => {
        if (!segment.includes(token) || index * 1000 + token.length <= score) return;
        score = index * 1000 + token.length;
        best = base.path;
      });
    }
    if (!best && loose && 1 === bases.length) best = bases[0].path;
    return best;
  };
  /** Second lines that name a media file, and the ones that name a picture, which a video key must not. */
  const VIDEO_FILE = /\.(mp4|m4v|mov|webm|mkv|wmv|avi|flv|m3u8|ts)(\?|$)/i;
  const IMAGE_FILE = /\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)(\?|$)/i;
  const VIDEO_LINE = /(^|[\/_.-])(flv|mp4|video|movie|stream|file)([\/_.-]|$)/i;
  /**
   * Custom fields worth suggesting for a feed that keeps a URL in two lines.
   *
   * A NATS video feed needs the joined video URL on a meta key a theme can read, and no mapping target carries
   * it, so the join has to be a custom field. The suggestion stays narrow on purpose: the second line has to
   * match a directory field by name and read like a media file, so an unrelated relative path never turns
   * into a guessed meta key. One row at most is suggested, and it is an ordinary row the admin can rename or
   * remove before anything is imported.
   *
   * @param {IexSchema} schema
   * @param {string} image The value autoMap() chose for the featured image.
   * @returns {IexCustomField[]}
   */
  function autoCustom(schema, image) {
    const bases = schema.fields.filter(isBase);
    const used = new Set([...pathParts(image), schema.path]);
    for (const field of schema.fields) {
      if (!field.sample || used.has(field.path) || bases.some((base) => base.path === field.path)) continue;
      if (/^[a-z][a-z0-9+.-]*:\/\//i.test(field.sample)) continue;
      if (IMAGE_FILE.test(field.path) || IMAGE_FILE.test(field.sample)) continue;
      if (!VIDEO_FILE.test(field.sample) && !VIDEO_LINE.test(field.path)) continue;
      const base = baseFor(field.path, bases);
      if (base) return [{ name: 'video_url', path: pathJoin(base, field.path), mode: 'first' }];
    }
    return [];
  }
  /**
   * The first field of this feed that answers to one of `wanted`, or an empty string.
   *
   * A field with an empty sample carries no value anywhere in this feed, so mapping it would import blanks;
   * `pathNames()` is what makes a prefixed or nested path answer to its own name.
   *
   * @param {IexSchema} schema
   * @param {string[]} wanted Field names to try, most preferred first.
   * @param {Set<string>} [skip] Paths another row already took.
   * @returns {string}
   */
  function findField(schema, wanted, skip = new Set()) {
    const empty = new Set(schema.fields.filter((f) => f.sample === '').map((f) => f.path));
    for (const name of wanted) {
      const found = schema.fields.find((f) => !empty.has(f.path) && !skip.has(f.path)
        && (f.path.toLowerCase() === name || pathNames(f.path).includes(name)));
      if (found) return found.path;
    }
    return '';
  }
  /**
   * @param {IexSchema} schema
   * @param {string[]} [termTargets] Term targets the chosen post type can store; every target when omitted.
   * @param {string[]} [imageNames] Field names the featured image may arrive under; the general list when omitted.
   */
  function autoMap(schema, termTargets, imageNames = IMAGE_NAMES) {
    const paths = schema.fields.map((f) => f.path);
    const namesOf = new Map(paths.map((p) => [p, pathNames(p)]));
    // A field with an empty sample carries no value anywhere in this feed; mapping it would import blanks.
    const empty = new Set(schema.fields.filter((f) => f.sample === '').map((f) => f.path));
    const urlAttributes = ['@url', '@href', '@src'];
    /** @param {string} path @param {string} name */
    const carriesUrl = (path, name) => !empty.has(path) && urlAttributes.some((a) => namesOf.get(path).includes(`${name}/${a}`));
    /**
     * @param {string[]} wanted Field names to try, most preferred first.
     * @param {boolean} [wantUrl] Prefer an attribute that carries the URL over the element itself.
     */
    const find = (wanted, wantUrl = false) => {
      for (const name of wanted) {
        const found = (wantUrl && paths.find((p) => carriesUrl(p, name)))
          || findField(schema, [name]);
        if (found) return found;
      }
      return '';
    };
    /**
     * A feed can keep a URL in two lines: a base directory in one field and the file name in another, so a
     * relative image path only works joined onto its base. Pair them when the best candidate is not an
     * absolute URL — `clips/clip/screens/screen` with `screen_url`, for example.
     *
     * @param {string} path Best single-field image candidate.
     * @returns {string}
     */
    const withBase = (path) => {
      if (!path) return path;
      const chosen = schema.fields.find((f) => f.path === path);
      if (!chosen || /^[a-z][a-z0-9+.-]*:\/\//i.test(chosen.sample || '')) return path;
      const bases = schema.fields.filter((f) => f.path !== path && !empty.has(f.path) && isBase(f));
      // A single directory-URL field is used for a value nothing matched by name: the join is visible and
      // editable in the row, whereas leaving the relative path alone would download nothing.
      const base = baseFor(path, bases, true);
      return base ? pathJoin(base, path) : path;
    };
    /**
     * Whether this term target is one the caller allows. Omitting the list allows all three; an empty list (a
     * post type that registers none) allows none, which is why this tests for an array rather than truthiness.
     *
     * @param {string} key
     * @returns {boolean}
     */
    const can = (key) => !Array.isArray(termTargets) || termTargets.includes(key);
    return {
      title: find(['title', 'name', '@name']), content: find(['description', 'content', 'summary']), excerpt: '',
      image: withBase(find(imageNames, true)),
      // RSS repeats <category>, JSON repeats an array and a table cell lists names with commas; one field feeds
      // the whole term list, so the first field that reads like one is suggested. A taxonomy the chosen post type
      // does not register stays unmapped, because the server refuses such a mapping instead of dropping it.
      categories: can('categories') ? find(TERM_NAMES.categories) : '',
      tags: can('tags') ? find(TERM_NAMES.tags) : '',
      // `@rdf:about` is RSS 1.0's identifier and flattens to a bare attribute on the record element.
      unique: find(['@id', '@rdf:about', 'adtoolid', 'id', 'guid', 'url']),
      // Actors is WP-Script's performer taxonomy, so a feed carrying performers usually has it. Last in the object
      // so the order the saved-feed summary and the presets show does not move.
      actors: can('actors') ? find(TERM_NAMES.actors) : '',
    };
  }
  /**
   * The rows a NATS-style video feed usually needs: the WordPress meta key a theme reads, and the feed fields
   * that carry it, most preferred first.
   */
  const NATS_ROWS = [
    ['video_url', ['video_url', 'movie_url', 'file', 'clip']],
    ['duration', ['clips/clip/duration', 'duration', 'video_length', 'length']],
    ['model_name', ['performers', 'model_name', 'model', 'performer', 'actor']],
    ['site_id', ['siteid', 'site_id']],
    ['site_name', ['paysite', 'site_name', 'site', 'studio']],
  ];
  /**
   * The field names a feed gives the hover-preview clip: `trailer_url` in WP-Script's own vocabulary, and
   * `preview_url`/`preview` in feeds and theme versions that call it a preview. One list, so a name cannot be
   * accepted by the trailer row and still be taken for the main video file.
   */
  const PREVIEW_NAMES = ['trailer_url', 'trailer', 'preview_url', 'preview'];
  /**
   * The meta WP-Script's own themes read from a video, and the feed fields that carry each one, most preferred
   * first. `duration` is stored in seconds by WP-Script's metabox, `thumbs` is one meta row per gallery image
   * (their themes implode get_post_meta( ..., 'thumbs', false )), and `hd_video` is the string `on`. The hover
   * preview answers to `trailer_url` and `preview_url` alike, because feeds and theme versions use both spellings;
   * it is never the film their player starts on.
   */
  const WP_SCRIPT_ROWS = [
    ['duration', ['duration', 'video_length', 'length', 'runtime', 'seconds']],
    ['trailer_url', PREVIEW_NAMES],
    ['embed', ['embed', 'embed_code', 'iframe']],
    ['shortcode', ['shortcode']],
    ['tracking_url', ['tracking_url', 'tracking', 'sponsor_url', 'sponsor']],
    ['hd_video', ['hd_video', 'hd']],
    ['post_views_count', ['post_views_count', 'views', 'view_count', 'video_viewed']],
    ['likes_count', ['likes_count', 'likes']],
    ['dislikes_count', ['dislikes_count', 'dislikes']],
    ['thumbs', ['thumbs', 'thumbnails', 'gallery', 'screens', 'screenshots', 'images']],
  ];
  /**
   * The WP-Script keys whose value is a site convention rather than feed data, written as fixed values.
   *
   * Their themes read `hd_video` as the string `on` and the counters as numbers, and a feed with no such field
   * would leave a video looking incomplete — or, worse, the preset would report keys the site can fill itself as
   * missing. The feed's own field still wins when it has one, because a feed that ships real view counts is
   * telling this site something the default cannot know.
   */
  const WP_SCRIPT_FIXED = { hd_video: 'on', post_views_count: '0', likes_count: '0', dislikes_count: '0' };
  /**
   * The per-quality keys WP-Script's metabox lists, in its own order. A feed that ships one file per quality gets
   * a row each, so switching quality in their player finds a file at every step; feeds with a single file simply
   * have none of these.
   */
  const WP_SCRIPT_QUALITIES = ['240', '360', '480', '720', '1080', '4k'];
  /** The names a feed may put in front of a quality: video_url_720, mp4_1080p, file_480, flv_360. */
  const QUALITY_PREFIXES = ['video_url', 'video', 'mp4', 'url', 'file', 'flv'];
  /**
   * The field names a quality may arrive under, most preferred first: `<prefix>_<quality>` for every prefix, then
   * the same with the `p` suffix (`mp4_1080p`), then the bare quality with and without it (`480`, `720p`).
   *
   * One rule instead of a hand-written list, so a prefix works with both spellings — the shipped list carried the
   * `p` forms for the `video` family only, and an `mp4_720p` feed quietly lost its quality key.
   *
   * @param {string} quality
   * @returns {string[]}
   */
  const qualityNames = (quality) => [
    ...QUALITY_PREFIXES.map((prefix) => `${prefix}_${quality}`),
    ...QUALITY_PREFIXES.map((prefix) => `${prefix}_${quality}p`),
    quality, `${quality}p`,
  ];
  /**
   * The field names a feed may give one screenshot when it splits a gallery across numbered fields instead of
   * repeating one element: `screen_1`, `screen_2`, `screen_3`, or `screenshot_1`, `image_2`, `pic_3`.
   */
  const SCREEN_NAME = /^(screens|screen|screenshots|screenshot|shots|shot|thumbs|thumb|images|image|pics|pic|pictures|picture|gallery)[_-]?(\d+)$/i;
  /**
   * The numbered screenshot fields of this feed, in their own order, so a gallery a single path cannot describe can
   * still be filled.
   *
   * A feed that writes `screen_1`, `screen_2`, `screen_3` gives each image its own element, and no one path holds
   * more than one of them. The most populated family wins (a feed carrying both `screen_1` and `thumb_1` should not
   * mix the two), its empty fields are skipped the way every other lookup skips them, and a family of one still
   * fills the gallery with that one image. Numbering is read as a number, so `screen_10` follows `screen_2`.
   *
   * @param {IexSchema} schema
   * @param {Set<string>} [skip] Paths another row already took.
   * @returns {string[]}
   */
  function screenFields(schema, skip = new Set()) {
    /** @type {Map<string, { path: string, at: number }[]>} */
    const families = new Map();
    for (const field of schema.fields) {
      if (skip.has(field.path) || field.sample === '') continue;
      const match = SCREEN_NAME.exec(pathNames(field.path).pop() || '');
      if (!match) continue;
      const family = match[1].toLowerCase();
      families.set(family, [...(families.get(family) || []), { path: field.path, at: Number(match[2]) }]);
    }
    /** @type {{ path: string, at: number }[]} */
    let best = [];
    for (const found of families.values()) if (found.length > best.length) best = found;
    return best.sort((a, b) => a.at - b.at).map((one) => one.path);
  }
  /**
   * Apply the NATS video preset: the featured image, the two-line video URL, and the duration/model/site rows,
   * all filled from the fields this feed actually has.
   *
   * Nothing here is invented — every path comes from the same lookup `autoMap()` uses, and the video URL comes
   * from the two-line join whenever the feed splits it — so the mapping that results is one the server accepts.
   * Recipe fields this feed does not have are counted and reported rather than guessed, and a row never takes
   * the field the featured image or the record identity already uses.
   *
   * @param {IexSchema} schema
   * @returns {{ mapping: Record<IexMappingKey, string>, custom: IexCustomField[], targets: number, rows: number, missed: number }}
   */
  function natsPreset(schema) {
    // This preset targets posts, which register categories and tags but no Actors, so it must not suggest one.
    const mapping = autoMap(schema, ['categories', 'tags']);
    const custom = autoCustom(schema, mapping.image);
    const names = new Set(custom.map((row) => row.name));
    const used = new Set([...pathParts(mapping.image), ...pathParts(mapping.unique)]);
    let missed = 0;
    for (const [name, wanted] of NATS_ROWS) {
      if (names.has(name)) continue;
      const path = findField(schema, wanted, used);
      if (!path) {
        missed++;
        continue;
      }
      custom.push({ name, path, mode: 'first' });
      names.add(name);
      used.add(path);
    }
    const targets = ['title', 'content', 'excerpt', 'image', 'unique'].filter((key) => mapping[key]).length;
    return { mapping, custom, targets, rows: custom.length, missed };
  }
  /**
   * Apply the WP-Script preset: WP-Script's own video fields, filled from the fields this feed actually has.
   *
   * Their themes read a fixed set of post meta (`video_url`, the per-quality `video_url_240` … `video_url_4k`
   * files their player switches between, the `thumb` poster their listings show, `duration` in seconds, the
   * hover preview under both `trailer_url` and `preview_url`, `embed`, `shortcode`, `hd_video`, the three
   * counters, and a `thumbs` gallery stored as one meta row per image) plus the Actors taxonomy. Every path comes from the same lookup the other presets use, a
   * row never takes a field the featured image, identity or a term target already uses, and the video URL row
   * comes from the two-line join when the feed splits it. `video_url` starts on the plain file when there is one,
   * joined to the feed's directory field when that file is relative, otherwise on the best quality the feed ships,
   * which is then not repeated as its own row; a trailer is never taken for it. Recipe fields this
   * feed does not have are counted, never guessed — except `WP_SCRIPT_FIXED`: `hd_video` and the three counters
   * are written as their convention values (`const:on`, `const:0`) when the feed has no field for them, so a
   * feed without that data still gets a complete video, and the count that is reported as missing stays honest.
   *
   * The term targets are the caller's list — the taxonomies the target post type registers — so the preset fills
   * Categories, Tags and Actors wherever the feed has a field for them and never leaves a mapping the server would
   * refuse at preview; `skippedTerms` reports a feed field the chosen type cannot store, because "the preset did not
   * map the performers" reads as a bug when it is WP-Script's taxonomy the type is missing.
   *
   * @param {IexSchema} schema
   * @param {string[]} [targets] Term targets the target post type registers; every target when omitted.
   * @param {string[]} [imageNames] Field names the featured image may arrive under; the general list when omitted.
   * @returns {{ mapping: Record<IexMappingKey, string>, custom: IexCustomField[], targets: number, rows: number, missed: number, fixed: number, terms: string[], skippedTerms: string[] }}
   */
  function wpScriptPreset(schema, targets = TERM_TARGETS, imageNames = IMAGE_NAMES) {
    const termTargets = Array.isArray(targets) ? targets : TERM_TARGETS;
    const mapping = autoMap(schema, termTargets, imageNames);
    const custom = autoCustom(schema, mapping.image);
    const names = new Set(custom.map((row) => row.name));
    const used = new Set([
      schema.path, ...pathParts(mapping.image), ...pathParts(mapping.unique),
      ...pathParts(mapping.categories), ...pathParts(mapping.tags), ...pathParts(mapping.actors),
    ]);
    let missed = 0;
    let fixed = 0;

    // One row per quality the feed actually ships. The value has to be a playable file — a quality key carrying a
    // page URL or a still is not a video — and a relative one is joined to the feed's directory field, the only one
    // when no name matches (as the featured image rule does); with several directories and no match it is left out.
    const bases = schema.fields.filter(isBase);
    const qualities = [];
    for (const quality of WP_SCRIPT_QUALITIES) {
      const path = findField(schema, qualityNames(quality), used);
      if (!path) continue;
      // Even a rejected key is taken: a field holding a still must not be retried as another quality, or picked as
      // the plain video file below.
      used.add(path);
      const sample = (schema.fields.find((f) => f.path === path) || {}).sample || '';
      if (!VIDEO_FILE.test(sample)) continue;
      const absolute = /^[a-z][a-z0-9+.-]*:\/\//i.test(sample);
      const base = absolute ? '' : baseFor(path, bases, true);
      if (!absolute && !base) continue;
      qualities.push({ name: `video_url_${quality}`, path: base ? pathJoin(base, path) : path });
    }

    // A trailer is not the video their player starts on: a feed that ships only a trailer must keep it under
    // `trailer_url`, or the row would appear twice and their player would play the preview as the film. A feed
    // that names the hover clip `preview_url` (or `preview`) is this same clip, so it must not be taken for the
    // main file either — that was the whole clip imported as the film, with the trailer reported missing.
    const isTrailer = (path) => pathNames(path).some((name) => PREVIEW_NAMES.includes(name));
    // `video_url` is the file their player starts with: a plain field when the feed has one, otherwise the best
    // quality it ships — which is then not repeated as its own row. A relative file is joined to the feed's
    // directory field exactly as a quality file is, and one with nothing to join is left out rather than stored as
    // a URL that cannot resolve.
    if (!names.has('video_url')) {
      let pick = null;
      for (const field of schema.fields) {
        // Every quality field is already in `used`, accepted or rejected, so this loop cannot take one.
        if (used.has(field.path)) continue;
        if (isTrailer(field.path) || !VIDEO_FILE.test(field.sample || '')) continue;
        const absolute = /^[a-z][a-z0-9+.-]*:\/\//i.test(field.sample);
        const base = absolute ? '' : baseFor(field.path, bases, true);
        if (!absolute && !base) continue;
        pick = { path: base ? pathJoin(base, field.path) : field.path };
        break;
      }
      if (!pick) pick = qualities.pop() || null;
      if (pick) {
        custom.push({ name: 'video_url', path: pick.path, mode: 'first', format: 'raw' });
        names.add('video_url');
      } else {
        missed++;
      }
    }
    for (const quality of qualities) {
      if (names.has(quality.name)) continue;
      custom.push({ name: quality.name, path: quality.path, mode: 'first', format: 'raw' });
      names.add(quality.name);
    }
    // Their listing templates read `thumb` as the poster URL, beside the featured image their importer also
    // sets, so it points at the same field — or at the feed's own poster field when the image is unmapped.
    if (!names.has('thumb')) {
      const poster = mapping.image || findField(schema, ['thumb', 'poster', 'thumbnail', 'preview', 'screen', 'still'], used);
      if (poster) {
        custom.push({ name: 'thumb', path: poster, mode: 'first', format: 'raw' });
        names.add('thumb');
      } else {
        missed++;
      }
    }

    for (const [name, wanted] of WP_SCRIPT_ROWS) {
      if (names.has(name)) continue;
      const path = findField(schema, wanted, used);
      // A feed that gives each screenshot its own numbered field has no repeated gallery field to map, so the row
      // carries the whole list instead: one meta key, one row per image, in the feed's own order.
      if (!path && 'thumbs' === name) {
        const gallery = screenFields(schema, used);
        if (gallery.length) {
          custom.push({ name, path: gallery[0], paths: gallery, mode: 'rows', format: 'raw' });
          names.add(name);
          gallery.forEach((one) => used.add(one));
          continue;
        }
      }
      if (!path) {
        // A convention key is written as text instead of being counted as a recipe field this feed lacks.
        if (Object.prototype.hasOwnProperty.call(WP_SCRIPT_FIXED, name)) {
          custom.push({ name, path: `const:${WP_SCRIPT_FIXED[name]}`, mode: 'first', format: 'raw' });
          names.add(name);
          fixed++;
          continue;
        }
        missed++;
        continue;
      }
      custom.push({
        name, path,
        mode: 'thumbs' === name ? 'rows' : 'first',
        format: 'duration' === name ? 'seconds' : 'raw',
      });
      names.add(name);
      used.add(path);
      // Their themes read the hover preview under one of two keys: `trailer_url` in the shipped themes, and
      // `preview_url` where the theme or the feed names it that way. One feed field fills both, so whichever key
      // the site reads finds the clip; a feed that ships both fields keeps each under its own key.
      if ('trailer_url' === name) {
        const own = findField(schema, ['preview_url', 'preview'], used);
        custom.push({ name: 'preview_url', path: own || path, mode: 'first', format: 'raw' });
        names.add('preview_url');
        if (own) used.add(own);
      }
    }
    const filled = ['title', 'content', 'excerpt', 'image', 'unique', 'categories', 'tags', 'actors'].filter((key) => mapping[key]).length;
    return {
      mapping, custom, targets: filled, rows: custom.length, missed, fixed,
      terms: termTargets.filter((key) => mapping[key]),
      skippedTerms: TERM_TARGETS.filter((key) => !termTargets.includes(key) && findField(schema, TERM_NAMES[key])),
    };
  }
  /**
   * The poster names a Tube Corporate feed answers to.
   *
   * The family runs one platform and names its main still `main_screenshot`, which answers to none of
   * IMAGE_NAMES exactly, so that name leads and the general ones stay as fallbacks for a feed built with the
   * platform's default columns.
   */
  const TUBE_CORPORATE_IMAGE = ['main_screenshot', 'screenshot', 'thumb', 'poster', 'preview', 'image'];
  /**
   * Apply the Tube Corporate preset: the WP-Script video fields, filled from the columns that family's partner
   * feed ships.
   *
   * Every site in the family (desiporn, hclips, hdzog, hotmovs, manysex, ourgay, ooxxx, pornl, senzuri,
   * shemaleporn, thegay, txxx, tubepornclassic, upornia, vjav, voyeurhit, xshemale) answers
   * `…/api/videos_feed2.php?csv_columns=…` with the same pipe-delimited CSV, so one preset covers them all:
   * `id`, `title`, `description`, `duration` in seconds, `video_url`, `embed`, `preview_url`,
   * `main_screenshot`, `screenshots`, `categories`, `tags`, `models`, `video_viewed` and `link`. Those are
   * ordinary CSV columns, so the importer reads the feed as it is and this preset only swaps the source
   * vocabulary: the recipe is wpScriptPreset's own — `models` is already in TERM_NAMES.actors, `preview_url` in
   * PREVIEW_NAMES, `screenshots` in the gallery names and `video_viewed` in the view-count names — with the
   * poster list above, because nothing else in the recipe knows `main_screenshot`. Two columns are left unused on
   * purpose: their themes read `hd_video` as the string `on` (so the fixed value applies even when `is_hd` is 0),
   * and `thumbs_up`/`thumbs_down` as percentages of one another, which one `rating` cannot fill without
   * arithmetic this plugin does not do.
   *
   * @param {IexSchema} schema
   * @param {string[]} [targets] Term targets the target post type registers; every target when omitted.
   * @returns {{ mapping: Record<IexMappingKey, string>, custom: IexCustomField[], targets: number, rows: number, missed: number, fixed: number, terms: string[], skippedTerms: string[] }}
   */
  function tubeCorporatePreset(schema, targets = TERM_TARGETS) {
    return wpScriptPreset(schema, targets, TUBE_CORPORATE_IMAGE);
  }
  /**
   * Apply a preset that fills WP-Script's video fields, and say what it filled.
   *
   * The target switches to the Videos post type first when the site has one, because the term targets the preset
   * may fill are the taxonomies that type registers. Both members of the family write the same destination
   * fields, so they share this hand-off as well as the recipe behind it.
   *
   * @param {string} label Preset name the notice leads with.
   * @param {(targets: string[]) => ReturnType<typeof wpScriptPreset>} build
   */
  function applyVideoPreset(label, build) {
    const type = ImportEverything.types.some((x) => x.value === 'video') ? 'video' : state.settings.type;
    const preset = build(termTargetsFor(type));
    state.settings.type = type;
    state.settings.mapping = preset.mapping;
    state.settings.custom = preset.custom;
    const labels = (keys) => keys.map((key) => ({ categories: t('Categories'), tags: t('Tags'), actors: t('Actors') }[key])).join(', ');
    state.info = preset.targets || preset.rows
      ? sprintf(t('%1$s preset applied: %2$s mapping fields and %3$s video fields (%4$s of them fixed values); %5$s recipe fields not present. It replaced the previous mapping and targets the Videos post type; review it before importing.'), label, preset.targets, preset.rows, preset.fixed, preset.missed)
        + (preset.terms.length ? ' ' + sprintf(t('It also mapped %s.'), labels(preset.terms)) : '')
        + (preset.skippedTerms.length ? ' ' + sprintf(t('%s left unmapped: this post type does not register that taxonomy.'), labels(preset.skippedTerms)) : '')
      : sprintf(t('%1$s preset: this feed has none of the fields the recipe needs.'), label);
    changed(); render();
  }
  /**
   * Analyze a feed. A saved feed is applied when one was opened, or when this URL matches one: the admin gets the
   * mapping they saved instead of the automatic one, and the notice says so.
   *
   * @param {object} payload
   * @param {File} [file]
   * @param {IexFeed} [saved]
   */
  async function loadSource(payload, file, saved) {
    applyLoaded(await request('source', payload, file), saved);
  }
  /**
   * Show an analyzed source on the mapping step: its mapping, then a saved feed's when one matches.
   *
   * @param {object} loaded Analyzed source from the server.
   * @param {IexFeed} [saved]
   */
  function applyLoaded(loaded, saved) {
    state.source = loaded;
    state.settings.mapping = autoMap(loaded.schema, termTargetsFor(state.settings.type));
    // A feed that keeps its video URL in two lines needs that join on a meta key, and no mapping target
    // carries it, so the join arrives as a ready-made custom row instead of a manual one.
    state.settings.custom = autoCustom(loaded.schema, state.settings.mapping.image);
    state.settings.identity = loaded.name;
    state.feedId = null;
    state.preview = null; state.job = null; state.tab = 'import'; state.step = 2;
    const match = saved || (loaded.url ? (state.feeds || []).find((feed) => feed.url === loaded.url) : undefined);
    if (match) {
      state.feedId = match.id;
      const missed = applySavedFeed(match);
      state.info = sprintf(t('Loaded the saved mapping for "%1$s". %2$s saved fields are not in this feed and were left out.'), match.name, missed);
    }
  }
  /**
   * The ordered fields a saved gallery row still names, or an empty list when the row no longer fits this feed.
   * The server accepts two to twelve fields and requires the row's own path to be the first, so a list that no
   * longer meets those rules is dropped rather than reopening the feed with a mapping that cannot be saved.
   *
   * @param {{ paths?: unknown }} row
   * @param {string} first The row's own field path.
   * @param {Set<string>} known Field paths this feed has.
   * @returns {string[]}
   */
  function galleryPaths(row, first, known) {
    if (!row || !Array.isArray(row.paths) || !first) return [];
    const paths = [...new Set(row.paths.filter((path) => typeof path === 'string' && known.has(path)))];
    return paths.length >= 2 && paths.length <= 12 && paths[0] === first ? paths : [];
  }
  /**
   * Replace the mapping with a saved feed's, keeping only the fields this feed still has.
   *
   * @param {IexFeed} feed
   * @returns {number} How many saved fields were left out.
   */
  function applySavedFeed(feed) {
    const fields = source().schema.fields;
    const known = new Set(fields.map((f) => f.path));
    /**
     * Whether a saved part still applies to this feed: an exact field, or a `field#n` whose base exists and
     * whose position the feed — and the dropdowns, capped at 5 — still offer. An exact path wins over the
     * suffix, exactly as the server reads it, so a JSON key ending in `#2` is never taken for a position.
     *
     * @param {string} part
     * @returns {boolean}
     */
    const usable = (part) => {
      if (known.has(part)) return true;
      const match = /^(.*)#(\d+)$/.exec(part);
      const at = match ? Number(match[2]) : 0;
      if (!match || !match[1] || at < 1) return false;
      const field = fields.find((f) => f.path === match[1]);
      // No value count means the dropdowns offer no position for that line, so keeping one would leave a row
      // showing a field the saved value no longer points at. The cap of 5 is what the dropdowns offer.
      return !!field && field.values > 0 && at <= Math.min(field.values, 5);
    };
    const keep = (value) => {
      const text = keptLiteral(value, known);
      if (null !== text) return text;
      const parts = pathParts(value);
      return parts.length && parts.length <= 2 && parts.every(usable) ? pathJoin(parts[0], parts[1]) : '';
    };
    /**
     * A term mapping is one field that may repeat: a join or a value position is refused by the server, so it is
     * dropped here rather than reopening the feed with a mapping that cannot be saved.
     *
     * @param {unknown} value
     * @returns {string}
     */
    const plain = (value) => {
      const text = keptLiteral(value, known);
      if (null !== text) return text;
      const parts = pathParts(value);
      return 1 === parts.length && known.has(parts[0]) ? parts[0] : '';
    };
    const saved = feed.settings || {};
    const mapping = { title: '', content: '', excerpt: '', image: '', unique: '', categories: '', tags: '', actors: '' };
    let missed = 0;
    for (const key of Object.keys(mapping)) {
      const savedValue = saved.mapping && saved.mapping[key];
      mapping[key] = TERM_TARGETS.includes(key) ? plain(savedValue) : keep(savedValue);
      if (savedValue && !mapping[key]) missed++;
    }
    const custom = [];
    for (const [name, row] of Object.entries(saved.custom || {})) {
      const path = keep(row.path);
      if (path) {
        const mode = row.mode || 'first';
        const paths = ['array', 'rows'].includes(mode) ? galleryPaths(row, path, known) : [];
        custom.push({ name, path, ...(paths.length ? { paths } : {}), mode, format: 'seconds' === row.format ? 'seconds' : 'raw' });
      } else missed++;
    }
    state.settings = {
      ...state.settings,
      type: ImportEverything.types.some((x) => x.value === saved.type) ? saved.type : 'post',
      status: ['draft', 'pending', 'publish'].includes(saved.status) ? saved.status : 'draft',
      identity: typeof saved.identity === 'string' && saved.identity ? saved.identity : state.settings.identity,
      duplicates: saved.duplicates === 'update' ? 'update' : 'skip',
      mapping,
      custom,
    };
    return missed;
  }
  async function refreshFeeds() {
    try {
      const list = await request('feeds');
      state.feeds = Array.isArray(list.feeds) ? list.feeds : [];
      state.feedsError = '';
    } catch (error) { state.feedsError = errorMessage(error); }
    render();
  }
  /**
   * Re-read the saved list when the browser tab becomes visible again, and rebuild the page only if something there
   * really changed. A schedule can record a failure in the minutes the admin is away, and `state.feeds` is otherwise
   * the copy the page loaded with, so the notice and the rows would wait for a reload; comparing first means a return
   * that finds nothing new cannot discard text typed into a field.
   *
   * A failed re-read stays quiet: the admin did not ask for this request, so it must not raise an error over work in
   * progress. A deliberate refresh still reports its own failure.
   */
  async function recheckFeeds() {
    let list;
    try { list = await request('feeds'); } catch (error) { return; }
    const feeds = Array.isArray(list.feeds) ? list.feeds : [];
    if (JSON.stringify(feeds) === JSON.stringify(state.feeds || [])) return;
    state.feeds = feeds;
    state.feedsError = '';
    render();
  }
  /**
   * The name the save card shows and sends: what was typed, else the saved feed's own name, else the analyzed
   * feed's. The middle step matters — re-saving an opened feed's mapping must not rename it back to its host.
   */
  function feedName() {
    if (state.feedName) return state.feedName;
    const saved = (state.feeds || []).find((feed) => feed.id === state.feedId);
    return saved ? saved.name : source().name;
  }
  async function saveFeed() {
    const saved = await request('feed_save', { id: source().id, feed: state.feedId || '', name: feedName(), settings: state.settings });
    state.feeds = saved.feeds;
    state.feedId = saved.feed.id;
    state.feedName = '';
    state.info = sprintf(t('Saved feed "%s". Opening or running it restores this mapping.'), saved.feed.name);
  }
  /** @param {IexFeed} feed */
  function openFeed(feed) {
    work(async () => applyLoaded(await request('feed_open', { feed: feed.id }), feed));
  }
  /** @param {IexFeed} feed */
  function runFeed(feed) {
    state.job = null;
    work(async () => {
      const started = await request('feed_run', { feed: feed.id });
      state.source = { id: started.id, name: started.name, schema: { total: started.total, path: started.path, fields: [] } };
      state.feedId = feed.id;
      state.job = started.job; state.preview = null; state.confirmed = false; state.step = 3; state.paused = false;
      // A run the server refuses still records `last_result`, so the list is reloaded from it rather than left
      // showing the state before the attempt: the row and the notice are then both the server's own answer.
    }).then(() => { if (state.job) runImport(); else refreshFeeds(); });
  }
  /** Run schedules a saved feed can take, in the order the select shows them. */
  function scheduleOptions() {
    return [
      { value: 'off', label: t('Never (manual)') },
      { value: 'hourly', label: t('Hourly') },
      { value: 'twicedaily', label: t('Twice daily') },
      { value: 'daily', label: t('Daily') },
    ];
  }
  /**
   * @param {IexFeed} feed
   * @param {string} schedule
   */
  function setSchedule(feed, schedule) {
    work(async () => {
      const list = await request('feed_schedule', { feed: feed.id, schedule });
      state.feeds = list.feeds;
      const label = scheduleOptions().find((option) => option.value === schedule);
      state.info = sprintf(t('Schedule for "%1$s" set to %2$s.'), feed.name, label ? label.label : schedule);
    });
  }
  /**
   * Delete the posts a feed imported. The feed, its mapping and its schedule are kept, so a later run imports
   * the same records again. Passes repeat until nothing is left or a pass deletes nothing.
   *
   * @param {IexFeed} feed
   */
  function clearPosts(feed) {
    work(async () => {
      const counted = await request('feed_posts', { feed: feed.id });
      if (!counted.posts) {
        state.info = sprintf(t('"%s" has no imported posts to delete.'), feed.name);
        return;
      }
      if (!window.confirm(sprintf(t('Permanently delete the %1$s posts imported from "%2$s"? The saved feed and its mapping are kept.'), counted.posts, feed.name))) return;
      let deleted = 0;
      let remaining = counted.posts;
      let pass;
      do {
        pass = await request('feed_clear', { feed: feed.id });
        deleted += pass.deleted;
        remaining = pass.remaining;
        state.info = sprintf(t('Deleted %1$s posts so far; %2$s left…'), deleted, remaining);
        render();
      } while (remaining > 0 && pass.deleted > 0);
      state.info = sprintf(t('Deleted %1$s posts from "%2$s". The saved feed is kept.'), deleted, feed.name)
        + (remaining > 0 ? ' ' + sprintf(t('%s posts were left because you may not delete them.'), remaining) : '');
    });
  }
  /** @param {IexFeed} feed */
  function deleteFeed(feed) {
    if (!window.confirm(sprintf(t('Delete the saved feed "%s"? Its imported posts are kept, and its schedule stops.'), feed.name))) return;
    work(async () => {
      const list = await request('feed_delete', { feed: feed.id });
      state.feeds = list.feeds;
      if (state.feedId === feed.id) state.feedId = null;
      state.info = sprintf(t('Deleted the saved feed "%s". Imported posts are kept.'), feed.name);
    });
  }
  /**
   * @param {IexFeed} feed
   * @returns {string}
   */
  function feedStatus(feed) {
    const last = feed.last_result;
    if (!last) return feed.last_run ? sprintf(t('Last run %s'), when(feed.last_run)) : t('Never run');
    if (last.status === 'failed') return sprintf(t('Last run failed %1$s: %2$s'), when(last.at), last.message);
    if (last.status === 'running') return t('Scheduled run in progress…');
    return sprintf(t('Last run %1$s: %2$s created, %3$s updated, %4$s skipped, %5$s failed'), when(last.at), last.created, last.updated, last.skipped, last.failed);
  }
  /**
   * Saved feeds whose latest recorded run failed. The server keeps one `last_result` per feed and overwrites it on
   * every run, so this is exactly what the Saved feeds rows show: a feed that failed and has since succeeded drops
   * out, and no failure the server stored can be missing from it.
   *
   * @returns {IexFeed[]}
   */
  function failedFeeds() {
    return (state.feeds || []).filter((feed) => feed.last_result && feed.last_result.status === 'failed');
  }
  /**
   * The notice shown above every tab but Saved feeds, where the rows carry the result and naming it again here
   * would be the same failure said twice.
   *
   * It has no live role on purpose: `render()` rebuilds the page on every batch, so an `alert` region would
   * re-announce the same failure every few seconds for as long as an import runs.
   *
   * @returns {HTMLElement|null}
   */
  function failuresNotice() {
    const failed = failedFeeds();
    if (!failed.length) return null;
    const box = node('div', { class: 'iex-alert iex-error' }, text(t('Saved feeds whose last run failed:')));
    for (const feed of failed) {
      box.append(text(sprintf(t('“%1$s” %2$s: %3$s'), feed.name, when(feed.last_result.at), feed.last_result.message || t('No message was recorded.'))));
    }
    box.append(button(t('Open Saved feeds'), () => {
      state.tab = 'feeds'; state.error = ''; state.info = ''; render(); refreshFeeds();
    }));
    return box;
  }
  /**
   * When this feed runs next, from the WP-Cron event the server reports. A schedule whose event WordPress
   * dropped says so plainly instead of showing a date that will never arrive.
   *
   * @param {IexFeed} feed
   * @returns {string}
   */
  /**
   * The post status a saved feed's runs write, as the list states it.
   *
   * The choice lives on the mapping step and is stored with the feed (`settings.status`), so this is a read of
   * what a run will write rather than a second control: an admin can see that a feed publishes without opening it.
   * A feed saved before that setting existed has no status and imports as a draft, which is what
   * `Importer::settings()` defaults to.
   *
   * @param {object} feed
   * @returns {string}
   */
  function feedPublish(feed) {
    const status = feed.settings && feed.settings.status;
    if ('publish' === status) return t('Imports as published');
    if ('pending' === status) return t('Imports as pending review');
    return t('Imports as drafts');
  }
  function feedNext(feed) {
    if (feed.next_run) return sprintf(t('Next run %s'), when(feed.next_run));
    return feed.schedule && feed.schedule !== 'off' ? t('Next run not scheduled yet') : t('Automatic runs are off');
  }
  /** @param {IexFeed} feed */
  function feedRow(feed) {
    if (state.editing === feed.id) return feedEditor(feed);
    const origin = feed.source === 'upload' ? t('Uploaded file, kept on this site') : '';
    const detail = [origin, feedPublish(feed), feedStatus(feed), feedNext(feed)].filter(Boolean).join(' · ');
    const urlDetails = feed.source === 'upload' ? null : node('details', { class: 'iex-feed-url-details' },
      node('summary', { class: 'iex-url-summary' },
        node('span', { class: 'iex-url-show' }, t('Show URL')),
        node('span', { class: 'iex-url-hide' }, t('Hide URL'))),
      node('code', { class: 'iex-feed-url' }, feed.url));
    const schedule = select(`iex-schedule-${feed.id}`, scheduleOptions(), feed.schedule || 'off', (value) => setSchedule(feed, value), { 'aria-label': t('Run schedule') });
    return node('div', { class: 'iex-history-row' },
      node('div', { class: 'iex-history-main' },
        node('div', { class: 'iex-history-name' }, node('strong', {}, feed.name),
          button(t('Edit'), () => editFeed(feed), 'secondary', { title: t('Rename this feed or change its address. Its mapping and schedule stay as they are.') })),
        text(detail, 'iex-hint'),
        urlDetails),
      node('div', { class: 'iex-history-actions' },
        schedule,
        button(t('Run import'), () => runFeed(feed), 'primary'),
        button(t('Edit mapping'), () => openFeed(feed)),
        button(t('Delete posts'), () => clearPosts(feed), 'secondary', { title: t('Deletes the posts this feed imported and keeps the feed, its mapping and its schedule.') }),
        button(t('Delete'), () => deleteFeed(feed))));
  }
  /** Open a saved feed's name and address for editing, in place of its row. @param {IexFeed} feed */
  function editFeed(feed) {
    state.editing = feed.id;
    state.editName = feed.name;
    state.editUrl = feed.source === 'upload' ? '' : feed.url;
    render();
  }
  /**
   * Rename a saved feed, or point it at a new address. The server reads the new address once and revalidates the
   * saved mapping against it before storing, so a changed URL cannot leave a run failing on a field that is gone.
   *
   * @param {IexFeed} feed
   */
  function saveFeedEdit(feed) {
    /** @type {{feed: string, name: string, url?: string}} */
    const payload = { feed: feed.id, name: state.editName };
    if (feed.source !== 'upload') payload.url = state.editUrl;
    work(async () => {
      const saved = await request('feed_update', payload);
      state.feeds = saved.feeds;
      state.editing = null;
      state.info = sprintf(t('Saved the feed “%s”.'), saved.feed.name);
    });
  }
  /** @param {IexFeed} feed */
  function feedEditor(feed) {
    const name = input(`iex-feed-edit-name-${feed.id}`, state.editName, (value) => { state.editName = value; }, { maxlength: '200' });
    const address = feed.source === 'upload' ? null : input(`iex-feed-edit-url-${feed.id}`, state.editUrl, (value) => { state.editUrl = value; }, { type: 'url', autocomplete: 'off' });
    return node('div', { class: 'iex-feed-editor' },
      field(t('Feed name'), name),
      address
        ? field(t('Feed URL'), address, t('Saving a new address reads the feed once more and checks this feed’s mapping against it. If a mapped field is gone, the address is refused and nothing changes.'))
        : text(t('An upload runs from the copy kept on this site. To replace the file, analyze it again from the source step; its name can still be changed here.'), 'iex-hint'),
      node('div', { class: 'iex-actions' },
        button(t('Save changes'), () => saveFeedEdit(feed), 'primary'),
        button(t('Cancel'), () => { state.editing = null; state.editUrl = ''; render(); })));
  }
  function feedsView() {
    const feeds = state.feeds || [];
    const card = node('div', { class: 'iex-card iex-history', id: 'iex-feeds' },
      node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Saved feeds')), node('span', { class: 'iex-badge iex-soft' }, String(feeds.length))));
    card.append(text(t('Saved feeds keep a mapping and a feed. A URL feed is read again on every run; an uploaded file runs from the copy kept on this site. A schedule runs the feed in the background with WP-Cron. Records that already exist are matched by identity, not duplicated.'), 'iex-hint'));
    if (state.feedsError) card.append(text(state.feedsError, 'iex-error-note'));
    else if (state.feeds === null) card.append(text(t('Loading saved feeds…'), 'iex-hint'));
    else if (!feeds.length) card.append(text(t('No saved feeds yet. Analyze a feed, adjust its mapping, then choose Save feed.'), 'iex-empty'));
    else for (const feed of feeds) card.append(feedRow(feed));
    return card;
  }
  function saveFeedView() {
    const nameInput = input('iex-feed-name', feedName(), (value) => { state.feedName = value; }, { maxlength: '200' });
    const hint = source().url
      ? t('Saved feeds keep the URL and this mapping. Running one later re-reads the feed and restores the same fields.')
      : t('Saved feeds keep this mapping and a private copy of the uploaded file, so they run without the file.');
    return node('div', { class: 'iex-card' }, node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Save this feed'))),
      text(hint, 'iex-hint'),
      field(t('Feed name'), nameInput),
      button(state.feedId ? t('Update saved feed') : t('Save feed'), () => work(saveFeed), 'primary'));
  }
  /** @param {string} name @param {unknown} value */
  function download(name, value) {
    const url = URL.createObjectURL(new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' }));
    const a = node('a', { href: url, download: name }); a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  /**
   * Download the support bundle for the current session: environment limits, mapped settings, the job
   * journal, and diagnostics. The feed file and its sample values never leave the server.
   */
  async function supportReport() {
    // `fields` is the opt-in the checkbox beside the button sets: field names only, never a value.
    download('import-everything-support.json', await request('support', { id: source().id, fields: state.supportFields }));
  }

  function sourceView() {
    /** @type {File | undefined} */
    let selectedFile;
    let url = '';
    const chooseLabel = t('Choose a feed file');
    const fileLabel = node('span', { class: 'iex-file-label' }, chooseLabel);
    const fileInput = node('input', { type: 'file', id: 'iex-file', accept: '.xml,.json,.csv,.tsv,application/xml,application/json,text/xml,text/csv,text/tab-separated-values,application/rss+xml,application/atom+xml', onchange: () => {
      selectedFile = fileInput.files?.[0];
      fileLabel.textContent = selectedFile ? `${selectedFile.name} · ${(selectedFile.size / 1024).toFixed(1)} KB` : chooseLabel;
    } });
    const fetchSource = () => work(() => loadSource({ url }));
    const analyzeFile = () => work(async () => {
      if (!selectedFile) throw new Error(t('Choose a feed file first.'));
      if (selectedFile.size > ImportEverything.uploadLimit) throw new Error(t('This feed file exceeds your server upload limit. Increase PHP upload/post limits, split the file, or fetch the feed by URL.'));
      await loadSource({}, selectedFile);
    });
    return node('div', { class: 'iex-card' }, node('div', { class: 'iex-kicker' }, t('START WITH YOUR DATA')), node('h2', {}, t('One feed. Every field.')),
      text(t('Bring XML, RSS, Atom, JSON or CSV — a file, a feed URL or a Google Sheet. We’ll find the records and uncover every field, including nested attributes and repeated values.')),
      field(t('Feed or Google Sheets URL'), input('iex-url', '', (v) => { url = v; }, { type: 'url', placeholder: 'https://example.com/feed.xml', autocomplete: 'off' }), t('Public HTTP(S) URLs only. Google Sheets links are read through their CSV export; the sheet must be shared or published. Feed credentials are never saved in mapping templates.')),
      button(t('Fetch & analyze'), fetchSource, 'primary'),
      node('div', { class: 'iex-divider' }, t('or upload a file')),
      node('label', { class: 'iex-upload' }, node('span', { class: 'dashicons dashicons-media-code', 'aria-hidden': true }),
        fileLabel, fileInput, node('span', { class: 'iex-hint' }, sprintf(t('XML, JSON, CSV and TSV files up to %s MiB on this server · kept private'), (ImportEverything.uploadLimit / 1048576).toFixed(1)))),
      button(t('Analyze file'), analyzeFile, 'primary'));
  }

  function fieldBrowser() {
    const list = node('div', { class: 'iex-field-list', id: 'iex-field-list' });
    const count = node('span', { class: 'iex-badge', id: 'iex-filter-count' });
    function update() {
      const filtered = source().schema.fields.filter((f) => f.path.toLowerCase().includes(state.filter.toLowerCase()));
      count.textContent = String(filtered.length); list.replaceChildren();
      for (const f of filtered) {
        const item = node('button', { type: 'button', class: 'iex-xml-field', draggable: true,
          title: t('Focus a mapping dropdown, then click this field. Or drag it onto a dropdown.'),
          onclick: () => {
            if (state.target?.isConnected) {
              state.target.value = f.path; state.target.dispatchEvent(new Event('change')); state.target.focus();
            } else addCustom(f.path);
          }, ondragstart: (event) => { if (event instanceof DragEvent) event.dataTransfer?.setData('text/plain', f.path); } },
          node('code', {}, f.path), node('span', { class: 'iex-field-sample' }, f.sample || t('(empty)')),
          node('span', { class: 'iex-hint' }, `${f.present}/${source().schema.total} ${t('records')}${f.repeated ? ` · ${t('repeated')}` : ''}`));
        list.append(item);
      }
      if (!filtered.length) list.append(text(t('No matching fields.')));
    }
    const search = node('input', { id: 'iex-search', type: 'search', value: state.filter, oninput: () => { state.filter = searchEl.value; update(); }, placeholder: t('Search field paths…'), 'aria-label': t('Search fields') });
    const searchEl = search;
    update();
    return node('aside', { class: 'iex-card iex-browser' }, node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Field explorer')), count),
      text(t('Focus a mapping dropdown, then click or drag a field. Click without a focused dropdown to add a custom field.'), 'iex-hint'), search, list);
  }
  function addCustom(path = '') {
    const tail = (path.split('/').pop() || '').replace(/^@/, '').replace(/[^a-zA-Z0-9_.-]/g, '_');
    let name = tail ? `feed_${tail}` : '';
    let suffix = 2;
    while (name && state.settings.custom.some((row) => row.name === name)) name = `feed_${tail}_${suffix++}`;
    state.settings.custom.push({ name, path, mode: 'first', format: 'raw' }); state.target = null; changed(); render();
    document.getElementById(`iex-meta-name-${state.settings.custom.length - 1}`)?.focus();
  }
  function mappingView() {
    const schema = source().schema;
    /** @type {[IexMappingKey, string, string][]} */
    const mappings = [['title', t('Post title'), t('Required. The first value is used.')], ['content', t('Post content'), t('Repeated values are joined with newlines. Safe HTML is preserved.')], ['excerpt', t('Post excerpt'), ''], ['categories', t('Categories'), t('Every value of this field becomes a category, and a value with commas lists several. Missing categories are created. Only post types that support categories can take them. A feed whose categories element is empty can be filed under a fixed category instead.')], ['tags', t('Tags'), t('Every value of this field becomes a tag, and a value with commas lists several. Missing tags are created. A feed whose tags element is empty can still carry a fixed tag.')], ['actors', t('Actors (WP-Script)'), t('WP-Script’s Actors taxonomy on the Videos post type — their themes list the performers from it. Leave it empty unless the target post type registers Actors. A fixed value files every record under the same actor.')], ['image', t('Featured image URL'), t('Downloads an image during import only. For attachments, this creates the media item. When the feed splits a URL across two lines — a base directory in one field and the file name in another — choose the base here and join the file name under “Then append”. When a line repeats, “value 2” picks its second value in the feed — on GetScoreCash that is the second <screen>, i.e. the other of the two screenshots.')], ['unique', t('Unique record identifier'), t('Recommended: @id, @rdf:about, adtoolid, or a stable URL. Unmapped records use a fingerprint of all values.')]];
    const card = node('div', { class: 'iex-card' }, node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Connect your fields')), node('span', { class: 'iex-badge iex-soft' }, t('Auto-suggestions applied'))),
      text(t('Start with our suggestions, then choose the fields that fit your site. Nothing is imported yet.'), 'iex-hint'));
    // The post type is chosen on the next step, so a term mapping the current one cannot store is flagged here
    // instead of surfacing as a refusal at preview, two steps after the choice that caused it.
    const supported = (ImportEverything.taxonomies || {})[state.settings.type] || [];
    const typeLabel = (ImportEverything.types || []).find((entry) => entry.value === state.settings.type)?.label || state.settings.type;
    for (const [key, label, hint] of mappings) {
      // A field change re-renders so the term hint below follows the post type; the fixed-value box sets the
      // same state without a re-render, or it would lose focus while it is being typed in.
      const set = (value) => { state.settings.mapping[key] = value; changed(); if (TERM_TARGETS.includes(key)) render(); };
      // The box uses a setter that never re-renders; `changed()` only marks the preview stale.
      const typed = (value) => { state.settings.mapping[key] = value; changed(); };
      const picker = 'image' === key
        ? mappingPair(`iex-map-${key}`, state.settings.mapping[key], set)
        : mappingSelect(`iex-map-${key}`, state.settings.mapping[key], set);
      card.append(field(label, TERM_TARGETS.includes(key)
        ? literalControl(`iex-map-${key}`, state.settings.mapping[key], typed, picker)
        : picker, hint));
      if (TERM_TARGETS.includes(key) && state.settings.mapping[key] && !supported.includes(key)) {
        card.append(text(sprintf(t('%1$s cannot store this taxonomy, so the preview will refuse it. Choose a different post type on the next step, or leave this field unmapped.'), typeLabel), 'iex-hint'));
      }
    }
    // One sentence per field this feed never fills, naming every target that reads it. Two targets can share one
    // empty field (categories and tags on the same <tags>), and the same warning twice reads as two problems.
    /** @type {Map<string, string[]>} */
    const stalled = new Map();
    for (const [key, label] of mappings) {
      const path = state.settings.mapping[key];
      if (path && emptyFieldPath(path)) stalled.set(path, [...(stalled.get(path) || []), label]);
    }
    for (const [value, labels] of stalled) {
      const targets = 2 === labels.length ? sprintf(t('%1$s and %2$s'), labels[0], labels[1]) : labels.join(', ');
      // A template names fields, not one path, so it is called empty only when every field it reads is empty.
      card.append(text('tpl' === literalParts(value)[0]
        ? sprintf(t('Every field this template reads is empty in this feed, so %s will stay empty.'), targets)
        : sprintf(t('%1$s has no values anywhere in this feed, so %2$s will stay empty.'), value, targets), 'iex-hint'));
    }
    const custom = node('div', { class: 'iex-card' }, node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Your custom fields')), button(t('+ Add field'), () => addCustom())),
      text(t('Enter the exact WordPress meta key your theme uses. Each feed field can be mapped more than once. Protected keys starting with an underscore are blocked. When two feed fields complete one URL, like a video base plus its file name, put the base here and join the file name under “Then append” — suggestions do that for you, adding a video_url row when the feed keeps its video URL in two lines. A row can also write text instead of reading the feed: type on for WP-Script’s hd_video, or {model_name} - {title} to build a value per record.'), 'iex-hint'));
    state.settings.custom.forEach((row, index) => {
      // Typed text is one value, so the modes that repeat a feed's values cannot describe it and are disabled
      // while the box is the value.
      const literal = !!literalParts(row.path)[0];
      /** @param {string} value */
      const typed = (value) => {
        if (literalParts(value)[0]) row.mode = 'first';
        // A converted duration is one value, so a joined path cannot keep the conversion.
        if (pathParts(value).length > 1) row.format = 'raw';
      };
      const metaRow = node('div', { class: 'iex-meta-row' },
        field(t('Custom field name'), input(`iex-meta-name-${index}`, row.name, (value) => { row.name = value; changed(); }, { placeholder: 'video_url' })),
        field(t('Feed field or fixed value'), literalControl(`iex-meta-path-${index}`, row.path, (value) => {
          row.path = value;
          delete row.paths;
          typed(value);
          changed();
        }, mappingPair(`iex-meta-path-${index}`, row.path, (value) => {
          row.path = value;
          delete row.paths;
          typed(value);
          changed();
        }))),
        field(t('Repeated values'), select(`iex-meta-mode-${index}`, [{ value: 'first', label: t('First value') }, { value: 'join', label: t('Join with newlines') }, { value: 'array', label: t('Store as array') }, { value: 'rows', label: t('One meta row per value') }], row.mode, (value) => {
          row.mode = value;
          // A chosen mode is a decision about this row, so a preset's gallery list does not outlive it.
          delete row.paths;
          // A joined value is one URL, so a mode that repeats values cannot keep the second field.
          if ('first' !== value) row.path = pathParts(row.path)[0] || '';
          if ('first' !== value) row.format = 'raw';
          changed(); render();
        }, { disabled: literal })),
        field(t('Value format'), select(`iex-meta-format-${index}`, [{ value: 'raw', label: t('As in the feed') }, { value: 'seconds', label: t('Duration → seconds') }], row.format || 'raw', (value) => {
          row.format = value;
          // A converted value reads one field's one value, so both are fixed when it is chosen.
          if ('seconds' === value) { row.mode = 'first'; row.path = pathParts(row.path)[0] || ''; delete row.paths; }
          changed(); render();
        }), t('WP-Script stores duration in seconds; this converts 05:00 or PT5M to 300.')),
        button('×', () => { state.settings.custom.splice(index, 1); changed(); render(); }, 'icon', { 'aria-label': sprintf(t('Remove custom field %s'), row.name || String(index + 1)) }));
      // A gallery the preset filled from numbered fields reads several paths; say which, because the picker above
      // can only show the first of them.
      if ((row.paths || []).length > 1) metaRow.append(text(sprintf(t('Gallery: %s — one meta row per image, in this order.'), row.paths.join(', ')), 'iex-hint'));
      custom.append(metaRow);
    });
    if (!state.settings.custom.length) custom.append(text(t('Add fields such as video_url, duration, gallery_url, or model_name. They’ll be saved as standard post metadata.'), 'iex-empty'));
    const templateInput = node('input', { id: 'iex-template', type: 'file', accept: '.json,application/json', class: 'iex-hidden', onchange: () => work(async () => {
      const file = templateInput.files?.[0];
      if (!file || file.size > 262144) throw new Error(t('Choose a mapping template smaller than 256 KiB.'));
      const template = JSON.parse(await file.text());
      if (template.format !== 'import-everything' || template.version !== 1 || !template.settings || !Array.isArray(template.settings.custom)) throw new Error(t('Not a supported Import Everything! template.'));
      /** @type {IexSettings} */
      const loaded = template.settings;
      const known = new Set(schema.fields.map((f) => f.path));
      /**
       * A template field is kept only when this target can hold it: a template can name a field this feed does
       * not have, and only the image and a "first value" custom row may carry two paths.
       *
       * @param {unknown} value
       * @returns {string}
       */
      const accepts = (/** @type {string} */ part) => { const m = /^(.*)#(\d+)$/.exec(part); return m ? known.has(m[1]) && Number(m[2]) >= 1 : known.has(part); };
      const only = (value) => { const text = keptLiteral(value, known); if (null !== text) return text; const parts = pathParts(value); return 1 === parts.length && accepts(parts[0]) ? parts[0] : ''; };
      const termOnly = (value) => { const text = keptLiteral(value, known); if (null !== text) return text; const parts = pathParts(value); return 1 === parts.length && known.has(parts[0]) ? parts[0] : ''; };
      /** @param {unknown} value @returns {string} */
      const paired = (value) => { const text = keptLiteral(value, known); if (null !== text) return text; const parts = pathParts(value).slice(0, 2); return parts.length && parts.every(accepts) ? pathJoin(...parts) : ''; };
      /** @type {Record<IexMappingKey, string>} */
      const mapping = { title: '', content: '', excerpt: '', image: '', unique: '', categories: '', tags: '', actors: '' };
      /** @type {IexMappingKey[]} */
      const keys = ['title', 'content', 'excerpt', 'image', 'unique', 'categories', 'tags', 'actors'];
      let missing = 0;
      for (const key of keys) {
        mapping[key] = 'image' === key ? paired(loaded.mapping?.[key]) : (TERM_TARGETS.includes(key) ? termOnly(loaded.mapping?.[key]) : only(loaded.mapping?.[key]));
        if (loaded.mapping?.[key] && !mapping[key]) missing++;
      }
      /** @type {IexCustomField[]} */
      const rows = [];
      for (const r of loaded.custom) {
        const mode = r && typeof r === 'object' ? r.mode : '';
        const path = ['first', 'join', 'array', 'rows'].includes(mode) ? ('first' === mode ? paired(r.path) : only(r.path)) : '';
        if (typeof r?.name !== 'string' || !path) { missing++; continue; }
        const paths = ['array', 'rows'].includes(mode) ? galleryPaths(r, path, known) : [];
        rows.push({ name: r.name, path, ...(paths.length ? { paths } : {}), mode, format: 'seconds' === r.format ? 'seconds' : 'raw' });
      }
      state.settings = { ...state.settings, mapping, custom: rows,
        type: ImportEverything.types.some((x) => x.value === loaded.type) ? loaded.type : 'post',
        status: ['draft', 'pending', 'publish'].includes(loaded.status) ? loaded.status : 'draft',
        duplicates: loaded.duplicates === 'update' ? 'update' : 'skip' };
      changed(); state.info = missing ? sprintf(t('Template loaded. %s missing field mappings were omitted; review before importing.'), missing) : t('Template loaded. Review the mapping and import identity.');
    }) });
    const top = node('div', { class: 'iex-card iex-record-bar' },
      field(t('Record element'), select('iex-record', schema.candidates.map((c) => ({ value: c.path, label: `${c.path} · ${c.count} ${t('records')}` })), schema.path, (path) => work(async () => {
        source().schema = await request('analyze', { id: source().id, path });
        state.settings.mapping = autoMap(source().schema, termTargetsFor(state.settings.type));
        state.settings.custom = autoCustom(source().schema, state.settings.mapping.image);
        state.filter = ''; changed();
      }), { disabled: state.busy }), t('Changing this resets field mappings. Nested NATS item groups are not selected by default.')),
      node('div', { class: 'iex-template-actions' }, button(t('Apply NATS preset'), () => {
        const preset = natsPreset(source().schema);
        state.settings.mapping = preset.mapping;
        state.settings.custom = preset.custom;
        state.info = preset.targets || preset.rows
          ? sprintf(t('NATS video preset applied: %1$s mapping fields and %2$s custom rows filled from this feed; %3$s recipe fields not present. It replaced the previous mapping; review it before importing.'), preset.targets, preset.rows, preset.missed)
          : t('NATS video preset: this feed has none of the fields the recipe needs.');
        changed(); render();
      }, 'secondary', { id: 'iex-preset', title: t('Fills the featured image, the two-line video URL, and the duration, model, and site rows from the fields this feed actually has. Replaces the current mapping.')       }), button(t('Apply WP-Script preset'), () => applyVideoPreset(t('WP-Script'), (targets) => wpScriptPreset(source().schema, targets)), 'secondary', { id: 'iex-wpscript-preset', title: t('Fills the video URL and its per-quality files (240p to 4K), the poster thumb, duration in seconds, the hover preview under both trailer_url and preview_url, embed and thumbs gallery from the fields this feed actually has — including a gallery split across separate numbered screenshot fields — plus categories, tags and Actors. hd_video is written as on and the counters as 0 when the feed ships none of them. Replaces the current mapping.')       }), button(t('Apply Tube Corporate preset'), () => applyVideoPreset(t('Tube Corporate'), (targets) => tubeCorporatePreset(source().schema, targets)), 'secondary', { id: 'iex-tubecorporate-preset', title: t('Fills the video fields a Tube Corporate partner feed ships — video_url, main_screenshot as the featured image and thumb poster, preview_url as the hover clip under both trailer_url and preview_url, embed, duration in seconds, video_viewed as the view count, the screenshots gallery, plus categories, tags and models as Actors. hd_video is written as on and the counters as 0. Replaces the current mapping.') }), button(t('Save mapping'), () => {
        const { identity, ...settings } = state.settings;
        download('import-everything-mapping.json', { format: 'import-everything', version: 1, settings });
      }), button(t('Load mapping'), () => templateInput.click()), templateInput));
    return node('section', {}, top, node('div', { class: 'iex-mapping-grid' }, node('div', {}, card, custom), fieldBrowser()), publishView(), saveFeedView(),
      node('div', { class: 'iex-footer' }, button(t('← Source'), () => { state.step = 1; render(); }), button(t('Continue to preview →'), () => { state.step = 3; render(); }, 'primary')));
  }

  /** The largest batch size this build accepts, from the server's own ceiling. */
  function batchMax() {
    return Number((ImportEverything.batch || {}).max) || 100;
  }
  /** The saved batch size, or the shipped default when the page was served by an older build. */
  function batchSize() {
    return Number((ImportEverything.batch || {}).size) || 5;
  }
  /**
   * The batch-size control: any whole number of records from 1 up to the server's ceiling.
   *
   * A list of preset sizes only offered what this build shipped, so a site whose host (or feed length) wanted a
   * different step had to take one of them. The number saves when the field is left or Enter is pressed, so typing
   * 250 does not send a request per digit; a value the server would refuse is caught here first, in the field's own
   * words, and the saved size comes back rather than a half-typed one staying on screen.
   */
  function batchField() {
    const max = batchMax();
    const el = node('input', { id: 'iex-batch', type: 'number', class: 'iex-batch-input', min: '1', max: String(max), step: '1', inputmode: 'numeric', value: String(batchSize()) });
    const save = () => {
      const size = Number(el.value);
      if (!Number.isInteger(size) || size < 1 || size > max) {
        state.error = sprintf(t('Choose a whole number of records per batch between 1 and %s.'), max);
        render();
        return;
      }
      work(async () => {
        const saved = await request('batch_size', { size });
        ImportEverything.batch = { ...(ImportEverything.batch || {}), ...saved };
        state.info = sprintf(t('Saved: %1$s records per batch. The rest of this import uses it after the current batch.'), saved.size);
      });
    };
    el.addEventListener('change', save);
    el.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); save(); } });
    return el;
  }
  /**
   * Fill the term targets the newly chosen post type can store and the mapping still leaves empty.
   *
   * The mapping step comes before this picker, so a feed carrying performers cannot offer Actors until the type
   * that registers it is chosen — and a saved feed's stored mapping, which may predate term targets, would
   * otherwise stay blank on the type that could take them. Only empty targets are filled, so an unmapped
   * taxonomy the admin chose to leave out is not brought back.
   */
  function fillTermTargets() {
    const allowed = termTargetsFor(state.settings.type);
    const auto = autoMap(source().schema, allowed);
    const filled = [];
    for (const key of TERM_TARGETS) {
      if (!allowed.includes(key) || state.settings.mapping[key] || !auto[key]) continue;
      state.settings.mapping[key] = auto[key];
      filled.push(`${key} (${auto[key]})`);
    }
    if (filled.length) state.info = sprintf(t('Filled the empty term targets this post type can store: %s.'), filled.join(', '));
  }
  /**
   * The draft/pending/publish choice, as one control for the two steps that show it.
   *
   * The mapping step shows it because that is where a feed is configured and saved: a saved feed — and every
   * scheduled run of it — keeps the status it was saved with, so a choice made only on the preview step would
   * never reach a background run. The preview step shows it beside the import button, where the import is
   * confirmed. Both write the same setting, and each step gets its own id so its label points at its own select.
   *
   * @param {string} id DOM id for this step's select.
   * @returns {HTMLElement}
   */
  function statusField(id) {
    return field(t('Post status'), select(id, [
      { value: 'draft', label: t('Draft — recommended') },
      { value: 'pending', label: t('Pending review') },
      { value: 'publish', label: t('Published — immediately public') },
    ], state.settings.status, (value) => { state.settings.status = value; changed(); }));
  }
  /**
   * The publishing choice on the mapping step, beside the feed it is saved with.
   *
   * Without it, the only control lived on the preview step: a feed saved from the mapping step stored the default
   * draft, so every scheduled and saved-feed run imported drafts and there was no way to choose otherwise from the
   * step that saves them.
   */
  function publishView() {
    return node('div', { class: 'iex-card' }, node('h2', {}, t('Publishing')),
      statusField('iex-status-map'),
      text(t('Applies to this import and to this saved feed, including its scheduled runs. Draft keeps the records out of the site until you publish them.'), 'iex-hint'));
  }
  function settingsView() {
    const s = state.settings;
    const controls = node('div', { class: 'iex-settings-grid' },
      field(t('Post type'), select('iex-type', ImportEverything.types, s.type, (value) => { s.type = value; fillTermTargets(); changed(); })),
      statusField('iex-status'),
      field(t('Import identity'), input('iex-identity', s.identity, (value) => { s.identity = value; changed(); }, { maxlength: '200' }), t('Keep the same identity and unique field on future runs to match existing records. Use a distinct identity for unrelated feeds.')),
      field(t('When a record already exists'), select('iex-duplicates', [{ value: 'skip', label: t('Skip existing records') }, { value: 'update', label: t('Update mapped fields') }], s.duplicates, (value) => { s.duplicates = value; changed(); }), t('Updates replace title, content, excerpt, status, categories, tags, and mapped custom fields. Unmapped custom fields remain.')),
      field(t('Records per batch'), batchField(), sprintf(t('Any whole number from 1 to %1$s records per batch. Each batch is one request that imports this many records: fewer means less memory used at once, which is what a small shared host runs out of, and more makes a thousand-record feed finish in fewer requests. 5 is the default.'), batchMax())));
    return node('div', { class: 'iex-card' }, node('h2', {}, t('Make it yours')), controls,
      text(t('Gallery and video URLs can be saved as custom fields. Video files and complete galleries are not downloaded. Attachment mode imports images; WordPress stores draft/pending attachments as private and published attachments as inherit. Media file URLs are not access-controlled. Existing attachments keep their media file on update.'), 'iex-notice'),
      button(t('Generate import preview'), () => work(async () => { state.preview = await request('preview', { id: source().id, settings: state.settings }); }), 'primary'));
  }
  function previewView() {
    const card = node('div', { class: 'iex-card', id: 'iex-preview' }, node('div', { class: 'iex-card-heading' }, node('h2', {}, t('A look before the leap')), node('span', { class: 'iex-badge' }, t('Text-only preview'))));
    if (!state.preview) card.append(text(t('Generate a preview to validate your mappings and inspect up to five records. No posts or media are created.'), 'iex-empty'));
    else {
      state.preview.forEach((record, index) => {
        const details = node('details', { class: 'iex-preview-record', open: index === 0 }, node('summary', {}, `${index + 1}. ${record.title || t('(empty title)')}`));
        const dl = node('dl', { class: 'iex-preview-data' });
        const terms = (/** @type {string[]} */ list) => (Array.isArray(list) && list.length ? list.join(', ') : '');
        // A mapped term field that resolves to nothing says which kind of nothing it is: a field this feed never
        // fills, or a record without a value. A dash alone reads as a broken mapping.
        const termValue = (/** @type {string} */ key, /** @type {unknown} */ list) => {
          const mapped = state.settings.mapping[key];
          if (Array.isArray(list) && list.length) return terms(list);
          if (!mapped) return '—';
          return emptyFieldPath(mapped) ? t('— empty in every record') : t('— none in this record');
        };
        for (const [key, value] of Object.entries({ [t('Content')]: record.content, [t('Excerpt')]: record.excerpt, [t('Image URL')]: record.image, [t('Categories')]: termValue('categories', record.categories), [t('Tags')]: termValue('tags', record.tags), [t('Actors')]: termValue('actors', record.actors), ...record.custom })) {
          dl.append(node('dt', {}, key), node('dd', {}, Array.isArray(value) ? JSON.stringify(value, null, 2) : value || '—'));
        }
        details.append(dl); card.append(details);
      });
      card.append(text(t('Safe HTML is shown as text, never executed. Remote images load only during the actual import.'), 'iex-hint'));
    }
    return card;
  }
  /**
   * Load the server's stored import history. The wizard works without it, so a failure is reported inside the
   * card rather than stopping the page.
   */
  async function refreshJobs() {
    try {
      const list = await request('jobs');
      state.jobs = Array.isArray(list.jobs) ? list.jobs : [];
      state.jobsMeta = { max: list.max, keptDays: list.kept_days, resumeHours: list.resume_hours };
      state.jobsError = '';
    } catch (error) { state.jobsError = errorMessage(error); }
    render();
  }
  /** @param {number} at Unix seconds. */
  const when = (at) => (at ? new Date(at * 1000).toLocaleString() : '');
  /** @param {string} value */
  function stateLabel(value) {
    return ({ running: t('In progress'), completed: t('Completed'), expired: t('Session expired'), cleared: t('Cleared') })[value] || value;
  }
  /** @param {IexJobEntry} entry */
  function historyRow(entry) {
    const subtitle = [entry.type || t('post'), entry.identity ? sprintf(t('Identity %s'), entry.identity) : t('No identity'), when(entry.checked)].join(' · ');
    const counts = sprintf(t('%1$s of %2$s records · %3$s created · %4$s updated · %5$s skipped · %6$s failed'),
      entry.offset, entry.total, entry.created, entry.updated, entry.skipped, entry.failed);
    const note = entry.state === 'expired' ? t('Not resumable: the feed session expired. Load the feed again to reconcile completed records.')
      : entry.state === 'completed' ? t('Finished.')
        : entry.state === 'cleared' ? t('Cleared from the wizard.')
          : t('Not resumable.');
    return node('div', { class: `iex-history-row iex-history-${entry.state}` },
      node('div', { class: 'iex-history-main' },
        node('strong', {}, entry.name || t('Untitled feed')),
        text(subtitle, 'iex-hint'),
        text(counts, 'iex-history-counts'),
        entry.in_flight ? text(t('The previous request stopped while a record was being written. Resuming completes it instead of creating a duplicate.'), 'iex-hint') : null),
      node('div', { class: 'iex-history-actions' },
        node('span', { class: 'iex-badge iex-soft' }, stateLabel(entry.state)),
        entry.resumable
          ? button(sprintf(t('Resume (%s left)'), entry.left), () => resumeImport(entry), 'primary')
          : text(note, 'iex-hint')));
  }
  /**
   * Continue a stored job in this tab: the session holds the settings, the spool and the counters, so this
   * only has to put the wizard back on its progress step and start asking for batches again.
   *
   * @param {IexJobEntry} entry
   */
  function resumeImport(entry) {
    state.source = { id: entry.id, name: entry.name, schema: { total: entry.total, path: entry.path, fields: [] } };
    state.settings = { ...state.settings, type: entry.type || 'post', status: entry.status || 'draft', identity: entry.identity || '', duplicates: entry.duplicates === 'update' ? 'update' : 'skip' };
    state.preview = null; state.confirmed = false; state.error = ''; state.info = '';
    state.job = { offset: entry.offset, total: entry.total, created: entry.created, updated: entry.updated, skipped: entry.skipped, failed: entry.failed, warnings: entry.warnings, log: Array.isArray(entry.journal) ? entry.journal : [] };
    state.tab = 'import'; state.step = 3; state.paused = false;
    render();
    runImport();
  }
  /**
   * The stored runs, as the page's own view rather than a card under the source step: a run is a record of what
   * happened, not a step of the next import, and twenty of them pushed the source form down the page.
   */
  function historyView() {
    const meta = state.jobsMeta || {};
    const card = node('div', { class: 'iex-card iex-history', id: 'iex-jobs' },
      node('div', { class: 'iex-card-heading' }, node('h2', {}, t('Recent imports')),
        node('div', { class: 'iex-actions' },
          node('span', { class: 'iex-badge iex-soft' }, sprintf(t('Last %s kept'), meta.max || 20)),
          button(t('Refresh'), () => refreshJobs(), 'secondary', { title: t('Reload the runs stored on this site.') }))));
    card.append(text(sprintf(t('Kept for %1$s days · an unfinished run resumes for %2$s hours after you close the tab.'), meta.keptDays || 7, meta.resumeHours || 24), 'iex-hint'));
    if (state.jobsError) card.append(text(state.jobsError, 'iex-error-note'));
    else if (state.jobs === null) card.append(text(t('Loading recent imports…'), 'iex-hint'));
    else if (!state.jobs.length) card.append(text(t('No imports yet. A run appears here as soon as you start one.'), 'iex-empty'));
    else for (const entry of state.jobs) card.append(historyRow(entry));
    return card;
  }
  function progressView() {
    const currentJob = job();
    /** @type {('created' | 'updated' | 'skipped' | 'failed' | 'warnings')[]} */
    const statKeys = ['created', 'updated', 'skipped', 'failed', 'warnings'];
    const done = currentJob.offset >= currentJob.total;
    return node('section', { class: 'iex-card' }, node('div', { class: 'iex-kicker' }, done ? t('IMPORT FINISHED') : t('IMPORT IN PROGRESS')),
      node('h2', {}, done ? t('Your feed has landed.') : state.running ? t('Connecting the dots…') : t('Import paused')),
      text(sprintf(t('%1$s of %2$s records processed'), currentJob.offset, currentJob.total), 'iex-progress-text'),
      node('progress', { max: currentJob.total, value: currentJob.offset, 'aria-label': t('Import progress') }),
      node('div', { class: 'iex-stats' }, ...statKeys.map((key) => node('div', {}, node('strong', {}, currentJob[key]), node('span', {}, ({ created: t('Created'), updated: t('Updated'), skipped: t('Skipped'), failed: t('Failed'), warnings: t('Image warnings') })[key])))),
      text(t('Pause takes effect after the current batch. You can close this tab: the run is stored on this site and can be resumed from the source step for 24 hours.'), 'iex-hint'),
      node('div', { class: 'iex-actions' },
        !done && (state.running ? button(t('Pause after this batch'), () => { state.paused = true; render(); }, 'secondary', { disabled: state.paused }) : button(t('Resume import'), runImport, 'primary')),
        button(t('Download report'), () => download('import-everything-import-report.json', { ...currentJob, settings: undefined, source: source().name, generatedAt: new Date().toISOString() })),
        !state.running && button(t('Start another import'), () => work(async () => {
          await request('clear', { id: source().id }); state.source = null; state.job = null; state.preview = null; state.step = 1;
        }).then(refreshJobs))),
      currentJob.log.length > 0 && node('div', { class: 'iex-import-log' }, node('h3', {}, t('Latest issues (up to 100)')), ...currentJob.log.map((row) => text(`${t('Record')} ${row.record}: ${row.message}`))));
  }
  /**
   * Ask for the next batch, waiting out a lock another request still holds. A request killed mid-batch
   * cannot release its own lock, so the server reports how long until it can be reclaimed: waiting it out
   * keeps the import moving instead of stopping on an error the admin would have to resume by hand.
   *
   * @param {string} id Session UUID.
   * @param {number} offset Next record to import.
   */
  async function nextBatch(id, offset) {
    let waited = 0;
    for (;;) {
      try {
        const next = await request('batch', { id, offset });
        state.info = '';
        return next;
      } catch (error) {
        const wait = error && error.retryAfter;
        state.info = '';
        if (!wait || state.paused || waited + wait > 300) throw error;
        waited += wait;
        state.info = sprintf(t('Waiting %s seconds for the previous batch to finish…'), wait);
        render();
        await new Promise((resolve) => setTimeout(resolve, wait * 1000));
      }
    }
  }
  async function runImport() {
    state.tab = 'import'; state.error = ''; state.running = true; state.paused = false; render();
    try {
      while (!state.paused && job().offset < job().total) {
        state.job = await nextBatch(source().id, job().offset); render();
      }
    } catch (error) { state.error = errorMessage(error); }
    finally { state.running = false; state.info = ''; render(); refreshJobs(); }
  }
  function reviewView() {
    if (state.job) return progressView();
    const confirmInput = node('input', { type: 'checkbox', id: 'iex-confirm', checked: state.confirmed, onchange: () => { state.confirmed = confirmInput.checked; render(); } });
    const confirmation = state.preview && node('label', { class: 'iex-confirm', for: 'iex-confirm' }, confirmInput,
      sprintf(t('I have reviewed the preview and want to import %1$s records as %2$s. I have a backup if updating existing content.'), source().schema.total, state.settings.status));
    return node('section', {}, settingsView(), previewView(), confirmation, node('div', { class: 'iex-footer' },
      button(t('← Field mapping'), () => { state.step = 2; render(); }),
      button(sprintf(t('Import %s records'), source().schema.total), () => {
        work(async () => { state.job = await request('start', { id: source().id, settings: state.settings }); }).then(() => { if (state.job) runImport(); });
      }, 'primary', { id: 'iex-import', disabled: !state.preview || !state.confirmed || state.busy })));
  }
  function render() {
    if (!root) return;
    root.replaceChildren();
    root.classList.add('iex-app');
    const header = node('header', { class: 'iex-header' },
      node('div', { class: 'iex-brand' }, node('span', { class: 'iex-logo', 'aria-hidden': true }, '⇥'), node('div', {}, node('h1', {}, 'Import', node('span', {}, ' Everything!')), text(t('Any feed. Any format. Your WordPress.'), 'iex-subtitle'))),
      node('span', { class: 'iex-badge iex-premium' }, t('Premium importer')));
    // The wizard and the stored runs are two views of the page, so the history is a tab of its own instead of a
    // card below the source form. Switching to it reloads the list, because a run can finish in another tab.
    const tabs = node('nav', { class: 'iex-tabs', 'aria-label': t('Sections') });
    [['import', t('Import')], ['feeds', t('Saved feeds')], ['jobs', t('Recent imports')]].forEach(([key, label]) => tabs.append(node('button', {
      type: 'button', class: `iex-tab ${state.tab === key ? 'is-active' : ''}`,
      'aria-current': state.tab === key ? 'page' : null,
      disabled: state.busy,
      onclick: () => {
        state.tab = key; state.error = ''; state.info = ''; render();
        if ('jobs' === key) refreshJobs();
        if ('feeds' === key) refreshFeeds();
      }
    }, label)));
    root.append(header, tabs);
    if (state.tab !== 'feeds') {
      const failures = failuresNotice();
      if (failures) root.append(failures);
    }
    if (state.error) root.append(node('div', { class: 'iex-alert iex-error', role: 'alert' }, state.error));
    if (state.info) root.append(node('div', { class: 'iex-alert', role: 'status' }, state.info));
    if (state.busy) root.append(node('div', { class: 'iex-alert', role: 'status', 'aria-live': 'polite' }, t('Working on your XML…')));
    if (state.tab === 'feeds') {
      root.append(feedsView());
    } else if (state.tab === 'jobs') {
      root.append(historyView());
    } else {
      const steps = node('nav', { class: 'iex-steps', 'aria-label': t('Import steps') });
      [t('Add source'), t('Map fields'), t('Preview & import')].forEach((label, index) => steps.append(node('button', {
        type: 'button', class: `iex-step ${state.step === index + 1 ? 'is-active' : ''}`,
        'aria-current': state.step === index + 1 ? 'step' : null,
        disabled: state.busy || state.running || (!!state.job && index !== 2 && index !== 1) || (!state.source && index > 0),
        onclick: () => { state.step = index + 1; state.error = ''; render(); }
      }, node('span', { class: 'iex-step-number' }, index + 1), label)));
      root.append(steps);
      if (state.source && state.step > 1) {
        // A resumed job has counters but no schema: the server holds the record path, so only the field count is
        // unavailable and calling it "0 fields" would be wrong.
        const sourceDetail = source().schema.fields.length
          ? `${source().schema.total} ${t('records')} · ${source().schema.fields.length} ${t('fields')} · ${source().schema.path}`
          : `${source().schema.total} ${t('records')} · ${source().schema.path}`;
        root.append(node('div', { class: 'iex-source-summary' }, node('strong', {}, source().name), node('span', {}, sourceDetail),
          node('label', { class: 'iex-support-fields', title: t('Adds this feed’s field paths to the report, so a support request can name the field that is missing. Field values are still never included.') },
            node('input', { type: 'checkbox', id: 'iex-support-fields', checked: state.supportFields, onchange: () => { state.supportFields = !state.supportFields; } }),
            t('Include field names')),
          button(t('Download support report'), () => work(supportReport), 'secondary', { id: 'iex-support', title: t('Your WordPress and PHP limits, the mapped settings, the current import journal, and diagnostics. The feed file and its sample values are never included.') })));
      }
      root.append(state.step === 1 ? sourceView() : state.step === 2 ? mappingView() : reviewView());
    }
  }
  window.addEventListener('beforeunload', (event) => { if (state.running) { event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => {
    if ('visible' === document.visibilityState && !state.busy) recheckFeeds();
  });
  render();
  refreshJobs();
  refreshFeeds();
})();
