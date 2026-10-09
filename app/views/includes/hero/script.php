<?php
// ============================================================================
// HERO / SCRIPT
// State of the hero (Alpine): active tab, search forms, AI trip planner.
// Forms are fetched from partials/search/{type}. On a tab switch the white
// tab slides, the forms cross-fade and the card grows or shrinks to fit.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<script data-cfasync="false">
  function homeHero() {
    return {
            activeTab: <?= json_encode($hero['initial']) ?>,
            validTabs: <?= json_encode(array_merge($hero['types'], $hero['ai'] ? ['ai'] : [])) ?>,
            searchLoaded: <?= json_encode((object) ($hero['formFile'] !== '' ? [$hero['initial'] => true] : [])) ?>,
            searchReq: {},
            tabTypes: <?= json_encode($hero['types']) ?>,
            cardH: 0,
            cardLive: false,
            cardTimer: null,
            preload: <?= json_encode($hero['preload']) ?>,
            root: <?= json_encode(root) ?>,
            datepicker: <?= json_encode(versionedAssetUrl('assets/js/datepicker.js')) ?>,
            aiSearchEnabled: <?= $aiSearchEnabled ? 'true' : 'false' ?>,
            aiQuery: "",
            aiError: "",
            aiLoading: false,
            aiRoot: <?= json_encode(root) ?>,
            aiEnabledModules: <?= json_encode($aiEnabledModuleTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
            aiSuggestions: <?= json_encode($aiSuggestionsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
            aiSuggestionsPreview: 5,
            aiMoreOpen: false,
            syncFromHash() {
                // ACCEPT BOTH #stays AND #/stays
                const h = (window.location.hash || "").replace(/^#\/?/, "").toLowerCase();
                if (h && this.validTabs.includes(h)) { this.activeTab = h; }
            },
            // SAFARI: PLAY CDN BUILDS CSS ASYNC. LAZY HTML MUST STAY HIDDEN UNTIL
            // @apply COMPONENTS (field-box) AND NEW UTILITIES ARE IN THE STYLESHEET.
            waitTailwindCdn(root) {
                return new Promise(resolve => {
                    const start = performance.now();
                    const ready = () => {
                        if (!window.tailwind) return false;
                        const probe = (root && root.querySelector)
                            ? (root.querySelector(".field-box") || root.querySelector(".field-box-icon") || root.querySelector(".absolute.left-3"))
                            : document.getElementById("tw-cdn-ready-probe");
                        if (!probe) return false;
                        const cs = getComputedStyle(probe);
                        if (probe.classList.contains("field-box") && cs.display === "flex") return true;
                        if (cs.position === "absolute") return true;
                        return false;
                    };
                    // TIMERS, NOT ANIMATION FRAMES: FRAMES STOP WHILE THE TAB IS NOT IN FRONT
                    const tick = () => {
                        if (ready() || performance.now() - start > 4000) { resolve(); return; }
                        setTimeout(tick, 40);
                    };
                    tick();
                });
            },
            async loadSearch(t) {
                if (!t || t === "ai") return;
                if (!this.validTabs.includes(t) || this.searchLoaded[t] || this.searchReq[t]) return;
                this.searchReq[t] = true;
                try {
                    const el = document.getElementById("search-panel-" + t);
                    if (!el) { this.searchLoaded[t] = true; return; }

                    // WAIT FOR INITIAL PLAY-CDN BUILD (SAFARI IS OFTEN SLOWER THAN CHROME)
                    await this.waitTailwindCdn(null);

                    const html = await fetch(this.root + "partials/search/" + t).then(r => r.ok ? r.text() : "");
                    if (!html) {
                        el.innerHTML = "<p class=\"text-sm text-red-600 text-center py-6\">Search form could not be loaded. Please refresh.</p>";
                        this.searchLoaded[t] = true;
                        return;
                    }
                    el.innerHTML = html;
                    el.querySelectorAll("script").forEach(old => {
                        const s = document.createElement("script");
                        // SKIP type: CLOUDFLARE ROCKET LOADER REWRITES IT ON THE
                        // LIVE SITE, WHICH WOULD STOP THE BROWSER EXECUTING THE
                        // SCRIPT. ALWAYS RUN AS PLAIN JS, OUTSIDE ROCKET LOADER.
                        for (const a of old.attributes) {
                            if (a.name === "type") continue;
                            s.setAttribute(a.name, a.value);
                        }
                        s.setAttribute("data-cfasync", "false");
                        s.textContent = old.textContent;
                        old.replaceWith(s);
                    });
                    // RE-RUN DATEPICKER SO IT PICKS UP INJECTED DATE FIELDS
                    const dp = document.createElement("script");
                    dp.src = this.datepicker;
                    dp.setAttribute("data-cfasync", "false");
                    document.body.appendChild(dp);

                    if (window.Alpine && typeof window.Alpine.initTree === "function") {
                        window.Alpine.initTree(el);
                    }

                    // THE FORM IS SHOWN ONLY ONCE THE CDN HAS STYLED IT — NO UNSTYLED FLASH
                    await this.waitTailwindCdn(el);
                    this.searchLoaded[t] = true;
                } finally {
                    if (!this.searchLoaded[t]) this.searchReq[t] = false;
                }
            },
            // ICONS ARE LETTERS UNTIL THEIR FONT HAS LOADED
            waitIcons() {
                // THE THIN CUT FOR THE TABS IS ADDED BY heroTabFont(): WAIT FOR IT, THEN FOR BOTH CUTS
                const font = (document.fonts && document.fonts.load)
                    ? Promise.resolve(window.heroTabFontReady).then(() => Promise.all([
                        document.fonts.load("24px 'Material Symbols Outlined'"),
                        document.fonts.load("200 40px 'Material Symbols Outlined Thin'"),
                    ])).catch(() => {})
                    : Promise.resolve();
                return Promise.race([font, new Promise(r => setTimeout(r, 3000))]);
            },
            // SCROLL BAR OF THE TABS: SHOWN WHEN THEY DO NOT ALL FIT, MOVES WITH THEM, CAN BE DRAGGED
            tabsBar() {
                const box = this.$refs.tabsBox, tabs = this.$refs.tabs, bar = this.$refs.tabsBar, thumb = this.$refs.tabsThumb;
                if (!box || !tabs || !bar || !thumb) return;
                const rtl = () => document.documentElement.dir === "rtl";
                const max = () => Math.max(tabs.scrollWidth - tabs.clientWidth, 0);
                const sync = () => {
                    const m = max();
                    box.classList.toggle("has-more", m > 1);
                    if (m <= 1) return;
                    const p = Math.min(Math.abs(tabs.scrollLeft) / m, 1);
                    box.classList.toggle("more-end", p < 0.98);
                    const w = Math.max(bar.clientWidth * tabs.clientWidth / tabs.scrollWidth, 36);
                    thumb.style.width = w + "px";
                    thumb.style.transform = "translateX(" + ((rtl() ? -1 : 1) * p * (bar.clientWidth - w)) + "px)";
                };
                const to = x => {
                    const r = bar.getBoundingClientRect(), w = thumb.offsetWidth;
                    let p = (x - r.left - w / 2) / Math.max(r.width - w, 1);
                    if (rtl()) p = 1 - p;
                    tabs.scrollLeft = (rtl() ? -1 : 1) * Math.min(Math.max(p, 0), 1) * max();
                };
                bar.addEventListener("pointerdown", e => {
                    bar.setPointerCapture(e.pointerId);
                    bar.classList.add("is-drag");
                    to(e.clientX);
                    const move = ev => to(ev.clientX);
                    const up = () => {
                        bar.classList.remove("is-drag");
                        bar.removeEventListener("pointermove", move);
                        bar.removeEventListener("pointerup", up);
                        bar.removeEventListener("pointercancel", up);
                    };
                    bar.addEventListener("pointermove", move);
                    bar.addEventListener("pointerup", up);
                    bar.addEventListener("pointercancel", up);
                });
                tabs.addEventListener("scroll", sync, { passive: true });
                window.addEventListener("resize", sync);
                if ("ResizeObserver" in window) new ResizeObserver(sync).observe(tabs);
                // THE ACTIVE TAB IS ALWAYS IN VIEW
                this.$watch("activeTab", () => this.$nextTick(() => {
                    const tab = tabs.querySelector(".hr-tab.is-active");
                    if (!tab || max() <= 1) return;
                    const t = tab.getBoundingClientRect(), b = tabs.getBoundingClientRect();
                    if (t.left < b.left) tabs.scrollBy({ left: t.left - b.left - 12, behavior: "smooth" });
                    else if (t.right > b.right) tabs.scrollBy({ left: t.right - b.right + 12, behavior: "smooth" });
                    // ONCE IT HAS ARRIVED: IS IT ON A CORNER OF THE CARD?
                    setTimeout(() => this.placeTab(), 450);
                }));
                sync();
            },
            // THE WHITE TAB TAKES THE PLACE AND THE WIDTH OF THE ACTIVE TAB (TABS MAY HAVE ANY WIDTH)
            placeTab() {
                const tabs = this.$refs.tabs, ind = this.$refs.tabInd;
                if (!tabs || !ind) return;
                const tab = tabs.querySelector('[data-tab="' + this.activeTab + '"]');
                if (!tab || !tab.offsetWidth) return;
                ind.style.setProperty("--x", tab.offsetLeft + "px");
                ind.style.setProperty("--w", tab.offsetWidth + "px");
                if (!tabs.classList.contains("is-measured")) {
                    void ind.offsetWidth;   // THE FIRST PLACE IS TAKEN AT ONCE, NOT WITH A SLIDE
                    tabs.classList.add("is-measured");
                }
                this.squareCorner(tab);
            },
            // THE CORNER OF THE CARD UNDER THE ACTIVE TAB IS SQUARE ONLY WHEN THE TAB SITS
            // ON IT: THE FIRST TAB AT THE START, OR (PHONES, TABS AS WIDE AS THE CARD) THE
            // LAST TAB AT THE END. A ROUND CORNER UNDER A STRAIGHT TAB EDGE LOOKED CUT.
            squareCorner(tab) {
                const card = this.$refs.card;
                if (!card || !tab) return;
                const t = tab.getBoundingClientRect(), c = card.getBoundingClientRect();
                const rtl = document.documentElement.dir === "rtl";
                const start = rtl ? c.right - t.right : t.left - c.left;
                const end = rtl ? t.left - c.left : c.right - t.right;
                // ON A CORNER: THE EDGE OF THE TAB IS WITHIN THE 12PX OF ITS CURVE FROM THE EDGE OF
                // THE CARD, OR PAST IT (THE ROW CUTS THE CURVE THERE), WHILE SOME OF THE TAB IS IN VIEW
                card.classList.toggle("is-first", start < 12 && start + t.width > 0);
                card.classList.toggle("is-last", end < 12 && end + t.width > 0);
            },
            // POSITION OF THE ACTIVE TAB (-1 WHEN THE AI PANEL IS OPEN): MOVES THE WHITE TAB
            tabIndex() {
                return this.tabTypes.indexOf(this.activeTab);
            },
            // THE CARD FOLLOWS THE HEIGHT OF THE ACTIVE FORM. THE HEIGHT IS FIXED ONLY
            // WHILE IT MOVES, THEN HANDED BACK TO THE PAGE, SO A FORM CAN NEVER BE CUT.
            fitCard() {
                const card = this.$refs.card;
                const panel = card && card.querySelector(".hr-panel.is-active");
                if (!panel) return;
                const moving = card.classList.contains("is-switching");
                const to = panel.offsetHeight;
                const from = moving ? card.offsetHeight : (this.cardH || card.offsetHeight);
                this.cardH = to;
                if (!this.cardLive || !to || Math.abs(from - to) < 1) return;
                clearTimeout(this.cardTimer);
                if (!moving) {
                    card.classList.add("is-switching");
                    card.style.height = from + "px";
                    void card.offsetHeight;
                }
                card.style.height = to + "px";
                this.cardTimer = setTimeout(() => {
                    card.classList.remove("is-switching");
                    card.style.height = "";
                }, 280);
            },
            animateTabSwitch() {
                this.$nextTick(() => this.fitCard());
            },
            start() {
                this.syncFromHash();
                if (this.activeTab !== "ai") this.loadSearch(this.activeTab);
                // THE CARD MOVES WITH ITS FORM: ON A TAB SWITCH, AND WHEN A FORM CHANGES
                // SIZE BY ITSELF (MULTI-CITY ROWS, A FORM THAT HAS JUST LOADED).
                this.tabsBar();
                this.placeTab();
                this.$watch("activeTab", () => this.placeTab());
                window.addEventListener("resize", () => this.placeTab());
                // A SWIPE OF THE TABS MOVES THE ACTIVE TAB ON OR OFF A CORNER
                if (this.$refs.tabs) {
                    this.$refs.tabs.addEventListener("scroll", () => this.placeTab(), { passive: true });
                    this.$refs.tabs.addEventListener("scrollend", () => this.placeTab(), { passive: true });
                }
                if ("ResizeObserver" in window && this.$refs.tabs) {
                    const ro = new ResizeObserver(() => this.placeTab());
                    this.$refs.tabs.querySelectorAll(".hr-tab").forEach(t => ro.observe(t));
                }
                const card = this.$refs.card;
                // THE SKELETON GOES, THE FORM COMES: ONLY WHEN STYLES AND ICONS ARE READY
                Promise.all([this.waitTailwindCdn(null), this.waitIcons()]).then(() => {
                    this.$root.classList.add("is-ready");
                    if (card) card.classList.remove("is-loading");
                    this.cardH = card ? card.offsetHeight : 0;
                    if (window.heroFitBackground) window.heroFitBackground();
                });
                if (card) {
                    this.cardH = card.offsetHeight;
                    if ("ResizeObserver" in window) {
                        const ro = new ResizeObserver(() => this.fitCard());
                        card.querySelectorAll(".hr-panel").forEach(p => ro.observe(p));
                    }
                    // NOT WHILE THE PAGE IS STILL BEING STYLED
                    const live = () => setTimeout(() => { this.cardH = card.offsetHeight; this.cardLive = true; }, 600);
                    if (document.readyState === "complete") live();
                    else window.addEventListener("load", live, { once: true });
                }
                this.$watch("activeTab", v => {
                    this.$nextTick(() => this.fitCard());
                    if (v !== "ai") this.loadSearch(v);
                    if (v && history.replaceState) history.replaceState(null, "", "#" + v);
                    if (v === "ai") this.$nextTick(() => this.$refs.aiSearchInput && this.$refs.aiSearchInput.focus());
                });
                window.addEventListener("hashchange", () => this.syncFromHash());

                // FETCH THE NEXT FORMS QUIETLY ONCE THE PAGE HAS SETTLED, SO THE
                // TABS PEOPLE USE MOST OPEN WITHOUT ANY WAIT.
                const warm = () => this.preload.forEach((t, i) => setTimeout(() => this.loadSearch(t), i * 400));
                const idle = () => ("requestIdleCallback" in window) ? requestIdleCallback(warm, { timeout: 3000 }) : setTimeout(warm, 1200);
                if (document.readyState === "complete") idle();
                else window.addEventListener("load", idle, { once: true });
            },
applyAiSuggestion(s) {
                if (!s) return;
                // Insert full plain query (or full label) into the search box
                const q = String(s.query || s.label || "").trim();
                this.aiQuery = q;
                this.aiError = "";
                this.aiMoreOpen = false;
                this.$nextTick(() => {
                    this.syncAiInputHtml();
                    if (this.$refs.aiSearchInput) this.$refs.aiSearchInput.focus();
                    this.animateTabSwitch();
                });
            },
            previewAiSuggestions() {
                const list = Array.isArray(this.aiSuggestions) ? this.aiSuggestions : [];
                return list.slice(0, this.aiSuggestionsPreview || 5);
            },
            moreAiSuggestions() {
                const list = Array.isArray(this.aiSuggestions) ? this.aiSuggestions : [];
                return list.slice(this.aiSuggestionsPreview || 5);
            },
            hasMoreAiSuggestions() {
                return this.moreAiSuggestions().length > 0;
            },
            aiSuggestionIconToken(s) {
                const label = String((s && s.label) || "");
                const m = label.match(/^:[a-z0-9_]+:(?:#[0-9A-Fa-f]{3,8}:)?/i);
                return m ? m[0] : ":auto_awesome:#0058E6:";
            },
            formatSuggestionLabel(label) {
                return String(label || "")
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/gi, (_, icon, hex) => {
                        const color = hex ? "#" + hex : "#0058E6";
                        return `<span class="material-symbols-outlined text-base align-middle mx-0.5 shrink-0" style="color:${color}">${icon}</span>`;
                    });
            },
            syncAiInputHtml() {
                const el = this.aiSearchBox();
                if (!el) return;
                el.innerHTML = this.formatSuggestionLabel(this.aiQuery || "");
            },
            aiSearchBox() {
                return this.$refs.aiSearchInput || document.getElementById("ai-home-q");
            },
            onAiInput() {
                const el = this.aiSearchBox();
                if (!el) return;
                this.aiQuery = this.serializeAiInput(el);
            },
            serializeAiInput(root) {
                let out = "";
                const walk = (node) => {
                    if (!node) return;
                    if (node.nodeType === 3) {
                        out += node.textContent || "";
                        return;
                    }
                    if (node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains("material-symbols-outlined")) {
                        const name = (node.textContent || "").trim().replace(/[^a-z0-9_]/gi, "");
                        if (!name) return;
                        const color = String(node.style && node.style.color || "").trim();
                        let hex = "";
                        const m = color.match(/#([0-9A-Fa-f]{3,8})/);
                        if (m) hex = m[1];
                        else if (/^rgb/i.test(color)) {
                            const nums = color.match(/\d+/g);
                            if (nums && nums.length >= 3) {
                                hex = nums.slice(0, 3).map((n) => Number(n).toString(16).padStart(2, "0")).join("");
                            }
                        }
                        out += hex ? (":" + name + ":#" + hex + ":") : (":" + name + ":");
                        return;
                    }
                    if (node.tagName === "BR") {
                        out += "\n";
                        return;
                    }
                    Array.from(node.childNodes || []).forEach(walk);
                };
                Array.from(root.childNodes || []).forEach(walk);
                return out.replace(/\u00a0/g, " ");
            },
            plainAiQuery() {
                return String(this.aiQuery || "")
                    .replace(/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/gi, " ")
                    .replace(/\s+/g, " ")
                    .trim();
            },
            _aiModuleKeywordMap() {
                return {
                    flights: ["flight", "flights", "fly", "airfare", "airline", "airport"],
                    stays: ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"],
                    cars: ["car", "cars", "rent a car", "rental", "vehicle"],
                    tours: ["tour", "tours", "activity", "activities", "excursion"],
                    visa: ["visa", "visas"],
                    umrah: ["umrah", "hajj"],
                    esim: ["esim", "e-sim", "e sim", "sim card", "data sim", "travel sim", "tourist sim", "data plan", "data package", "mobile data", "roaming"],
                    cruises: ["cruise", "cruises"],
                    ferries: ["ferry", "ferries"],
                    bus: ["bus", "buses", "coach"],
                    rail: ["rail", "train", "trains", "railway", "whoosh"]
                };
            },
            _aiModuleLabel(mod) {
                const map = {
                    flights: "Flights", stays: "Hotels", cars: "Cars", tours: "Tours",
                    visa: "Visa", umrah: "Umrah", esim: "eSIM", cruises: "Cruises",
                    ferries: "Ferries", bus: "Bus", rail: "Trains"
                };
                const m = String(mod || "").toLowerCase();
                return map[m] || (m ? (m.charAt(0).toUpperCase() + m.slice(1)) : "Travel");
            },
            detectAiModulesInQuery(q) {
                const text = String(q || "").toLowerCase();
                if (!text) return [];
                const map = this._aiModuleKeywordMap();
                const found = [];
                Object.keys(map).forEach((mod) => {
                    const hit = (map[mod] || []).some((word) => {
                        const w = String(word || "").toLowerCase();
                        if (!w) return false;
                        try {
                            return new RegExp("\\b" + w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "\\b", "i").test(text);
                        } catch (e) {
                            return text.indexOf(w) !== -1;
                        }
                    });
                    if (hit) found.push(mod);
                });
                return found;
            },
            disabledAiModulesOnlyMessage(q) {
                const enabled = (Array.isArray(this.aiEnabledModules) ? this.aiEnabledModules : [])
                    .map((m) => String(m || "").toLowerCase()).filter(Boolean);
                const requested = this.detectAiModulesInQuery(q);
                if (!requested.length) return "";
                const allowed = requested.filter((m) => enabled.indexOf(m) !== -1);
                if (allowed.length) return "";
                const labels = requested.map((m) => this._aiModuleLabel(m)).filter(Boolean);
                const avail = enabled.map((m) => this._aiModuleLabel(m)).filter(Boolean);
                let msg = labels.length === 1
                    ? (labels[0] + " is not available for AI Trip right now.")
                    : "That travel option is not available for AI Trip right now.";
                if (avail.length) {
                    const examples = avail.slice(0, 3);
                    msg += " Try " + examples.join(", ")
                        + (avail.length > 3 ? ", or another shown on top" : "")
                        + ".";
                } else {
                    msg += " Please try another travel option.";
                }
                return msg;
            },
            missingFlightRouteMessage(q) {
                if (!this._isAiFlightAsk(q)) return "";
                if (this._aiPromptHasArrival(q)) return "";
                return "Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).";
            },
            _isAiFlightAsk(q) {
                const text = String(q || "").trim().toLowerCase();
                if (!text) return false;
                const requested = this.detectAiModulesInQuery(text);
                return requested.indexOf("flights") !== -1
                    || text.indexOf("airfare") !== -1
                    || text.indexOf("round trip") !== -1
                    || text.indexOf("round-trip") !== -1
                    || text.indexOf("one way") !== -1
                    || text.indexOf("one-way") !== -1;
            },
            _aiPromptHasArrival(q) {
                const text = String(q || "").trim().toLowerCase();
                if (!text) return false;
                if (text.indexOf(" to ") !== -1 || text.indexOf("to ") === 0) return true;
                if (/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+[a-z]/i.test(text)) {
                    const m = text.match(/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
                    const place = m && m[1] ? String(m[1]).trim() : "";
                    const filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|flights?|fly)$/i;
                    if (place && !filler.test(place)) return true;
                }
                return this._aiCityBeforeFlight(text) !== "";
            },
            _aiCityBeforeFlight(q) {
                const text = String(q || "").trim().toLowerCase();
                const stops = ["find","look","looking","get","getting","show","give","see","check","me","a","an","the","cheap","cheapest","need","want","book","search","please","for","from","with","and","or","my","our","some","any","best","good","last","minute","deal","deals","offer","offers","option","options","available","ticket","tickets","tomorrow","today","tonight","now","next","this","that","round","trip","one","way","flight","flights","fly","airfare"];
                const words = text.replace(/[^a-z0-9\s-]/g, " ").split(/\s+/).filter(Boolean);
                let flightAt = -1;
                for (let i = 0; i < words.length; i++) {
                    if (words[i] === "flight" || words[i] === "flights" || words[i] === "fly") {
                        flightAt = i;
                        break;
                    }
                }
                if (flightAt < 1) return "";
                for (let i = flightAt - 1; i >= 0; i--) {
                    const w = words[i];
                    if (w.length >= 3 && stops.indexOf(w) === -1) return w;
                }
                return "";
            },
            runAiSearch() {
                if (window.homeAiSearch && typeof window.homeAiSearch.submit === "function") {
                    window.homeAiSearch.submit();
                }
            }
    };
  }
</script>
