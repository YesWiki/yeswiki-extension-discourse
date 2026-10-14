/* <discourse-activity entry="EntryTag">: fills itself with the entry's forum activity once it scrolls into view. */
class DiscourseActivity extends HTMLElement {
  static get observedAttributes() {
    return ['entry']
  }

  connectedCallback() {
    this.style.display = 'inline-block'
    this.watch()
  }

  disconnectedCallback() {
    this.observer?.disconnect()
  }

  attributeChangedCallback(name, previous, current) {
    if (previous !== null && previous !== current && this.isConnected) {
      this.innerHTML = ''
      this.watch()
    }
  }

  watch() {
    this.observer?.disconnect()
    if (!('IntersectionObserver' in window)) {
      this.load()
      return
    }
    this.observer = new IntersectionObserver((seen) => {
      if (seen.some((item) => item.isIntersecting)) {
        this.observer.disconnect()
        this.load()
      }
    }, { rootMargin: '200px' })
    this.observer.observe(this)
  }

  load() {
    const entry = this.getAttribute('entry')
    if (!entry || typeof wiki === 'undefined') return
    fetch(wiki.url(`api/discourse/entries/${encodeURIComponent(entry)}/activity`), { credentials: 'same-origin' })
      .then((response) => (response.status === 200 ? response.text() : ''))
      .then((html) => {
        if (this.getAttribute('entry') === entry) this.innerHTML = html
      })
      .catch(() => {})
  }
}

if (!customElements.get('discourse-activity')) customElements.define('discourse-activity', DiscourseActivity)
