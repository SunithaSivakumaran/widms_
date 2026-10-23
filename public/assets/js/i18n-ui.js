(() => {
 const start = () => {
  const translations = window.WIDMS_TRANSLATIONS || {}
  const entries = Object.entries(translations)
  if (entries.length === 0 || !document.body) return
  const excluded = 'script, style, textarea, code, pre, [contenteditable]:not([contenteditable="false"]), [translate="no"], [data-no-translate], .notranslate'
  const attributes = ['placeholder', 'title', 'aria-label']

  // Translate only visible interface text; user-entered values and application data stay intact.
  const translateText = (value) => {
    const trimmed = value.trim()
    const direct = translations[trimmed]
    if (direct) return value.replace(trimmed, () => direct)

    // Allow a decorative icon before an exact label, but never replace words inside data.
    for (const [english, translated] of entries) {
      if (!trimmed.endsWith(english)) continue
      const prefix = trimmed.slice(0, -english.length)
      if (/^[^\p{L}\p{N}]*$/u.test(prefix)) return value.replace(trimmed, () => `${prefix}${translated}`)
    }
    return value
  }

  const translateNode = (node) => {
    const parent = node.parentElement
    if (!parent || parent.closest(excluded) || parent.tagName === 'OPTION') return
    const original = node.nodeValue || ''
    const translated = translateText(original)
    if (translated !== original) node.nodeValue = translated
  }
  const translateElement = (element) => {
    if (element.closest(excluded)) return
    attributes.forEach((attribute) => {
      if (element.hasAttribute(attribute)) {
        const original = element.getAttribute(attribute) || ''
        const translated = translateText(original)
        if (translated !== original) element.setAttribute(attribute, translated)
      }
    })
    if (element.tagName === 'OPTION') {
      const original = element.textContent || ''
      const translated = translateText(original)
      if (translated !== original) {
        // Without an explicit value, translating the label changes submitted data.
        if (!element.hasAttribute('value')) element.setAttribute('value', element.value)
        element.textContent = translated
      }
    }
  }
  const translateRoot = (root) => {
    if (root.nodeType === Node.TEXT_NODE) { translateNode(root); return }
    if (root.nodeType !== Node.ELEMENT_NODE || root.closest(excluded)) return
    translateElement(root)
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
    const nodes = []
    while (walker.nextNode()) nodes.push(walker.currentNode)
    nodes.forEach(translateNode)
    root.querySelectorAll('[placeholder], [title], [aria-label], option').forEach(translateElement)
  }
  window.widmsTranslateUi = translateRoot
  translateRoot(document.body)
  // Translate AJAX dialogs and recreated controls without rescanning the full page.
  const observer = new MutationObserver((records) => {
    const roots = new Set()
    records.forEach((record) => {
      if (record.type === 'childList') {
        record.addedNodes.forEach((node) => roots.add(node))
        if (record.target.nodeType === Node.ELEMENT_NODE && record.target.tagName === 'OPTION') roots.add(record.target)
      } else if (record.type === 'characterData' && record.target.parentElement?.tagName === 'OPTION') {
        roots.add(record.target.parentElement)
      } else roots.add(record.target)
    })
    roots.forEach((root) => { if (root.isConnected) translateRoot(root) })
  })
  observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: attributes })
 }
 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true })
 else start()
})()
