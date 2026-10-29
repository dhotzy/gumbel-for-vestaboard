(() => {
    // Übersetzung: Texte kommen als JSON aus <script id="i18n-data"> (View::jsTexts; kein Inline-Skript wegen CSP), Platzhalter {name}
    let I18N = {};
    try { I18N = JSON.parse(document.getElementById('i18n-data')?.textContent || '{}'); } catch (e) { I18N = {}; }
    const tr = (s, params = {}) => Object.entries(params).reduce((out, [key, value]) => out.split(`{${key}}`).join(String(value)), I18N[s] || s);
    const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';

    // Darstellung umschalten: Auto (System) → Hell → Dunkel, gemerkt im Browser (data-mode bleibt deutsch, CSS hängt daran)
    const themes = { '': ['Auto', '◐', 'Darstellung: Auto'], light: ['Hell', '☀', 'Darstellung: Hell'], dark: ['Dunkel', '☾', 'Darstellung: Dunkel'] };
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const label = () => {
            const [name, , title] = themes[document.documentElement.dataset.theme ?? ''];
            button.dataset.mode = name;
            button.title = button.ariaLabel = tr(title);
        };
        label();
        button.addEventListener('click', () => {
            const keys = Object.keys(themes);
            const next = keys[(keys.indexOf(document.documentElement.dataset.theme ?? '') + 1) % keys.length];
            if (next) document.documentElement.dataset.theme = next; else delete document.documentElement.dataset.theme;
            try { if (next) localStorage.setItem('theme', next); else localStorage.removeItem('theme'); } catch (e) {}
            label();
        });
    });

    // Kopfzeile bekommt beim Scrollen eine Trennlinie
    const topbar = document.querySelector('.topbar');
    if (topbar) {
        const onScroll = () => topbar.classList.toggle('scrolled', window.scrollY > 4);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // Doppelklick-Schutz + Bestätigung für Formulare mit data-busy / data-confirm
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const confirmClick = event.submitter?.dataset.confirmClick;
        if (confirmClick && !window.confirm(confirmClick)) {
            event.preventDefault();
            return;
        }
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (form.hasAttribute('data-busy')) {
            if (form.dataset.submitted) {
                event.preventDefault();
                return;
            }
            form.dataset.submitted = '1';
            const button = event.submitter ?? form.querySelector('button[type="submit"]');
            if (button) {
                button.classList.add('is-busy');
                setTimeout(() => { button.disabled = true; }, 0);
            }
        }
    });

    // Live-Vorschau: Textfeld [data-preview-input] → Board [data-preview-target]
    const initPreview = (input) => {
        const scope = input.closest('[data-preview-scope], form, section') ?? document;
        const target = scope.querySelector('[data-preview-target]');
        const warnings = scope.querySelector('[data-preview-warnings]');
        const counter = scope.querySelector('[data-preview-counter]');
        const submit = scope.querySelector('[data-preview-submit]');
        if (!target) return;
        let timer = null;
        let controller = null;
        const currentAlign = () => scope.querySelector('[data-preview-align]:checked')?.value ?? 'center';
        let lastAlign = currentAlign();
        let brush = '{red}';
        const payload = input.dataset.payload ?? '';

        const fetchPreview = (text, align, signal) => {
            // Webhook-Vorlagen: Server füllt Platzhalter samt Filtern mit dem letzten Payload – wie beim echten Empfang
            const body = new URLSearchParams({ text, align, csrf, fit: 'fit' in input.dataset ? '1' : '', payload: input.dataset.payload ?? '' });
            return fetch('api/preview.php', { method: 'POST', body, signal })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)));
        };

        const update = () => {
            controller?.abort();
            controller = new AbortController();
            fetchPreview(input.value, currentAlign(), controller.signal)
                .then((data) => {
                    // Geänderte Kacheln markieren → kurze Split-Flap-Animation
                    const before = [...target.querySelectorAll('.vb-cell')].map((c) => c.className + c.textContent);
                    target.innerHTML = data.html;
                    target.querySelectorAll('.vb-cell').forEach((cell, i) => {
                        if (before.length && before[i] !== cell.className + cell.textContent) cell.classList.add('changed');
                    });
                    if (submit && !('locked' in submit.dataset)) submit.disabled = !data.fits;
                    if (counter) {
                        counter.textContent = data.counter;
                        counter.classList.toggle('over', !data.fits);
                    }
                    if (warnings) {
                        warnings.innerHTML = '';
                        data.warnings.forEach((text) => {
                            const li = document.createElement('li');
                            li.textContent = text;
                            warnings.appendChild(li);
                        });
                    }
                })
                .catch((error) => {
                    if (error?.name !== 'AbortError' && warnings) {
                        warnings.innerHTML = '';
                        const li = document.createElement('li');
                        li.textContent = tr('Vorschau gerade nicht verfügbar.');
                        warnings.appendChild(li);
                    }
                });
        };
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(update, 200);
        };
        input.addEventListener('input', schedule);
        if (payload) update();

        // Designer: Text als exaktes 3×15-Raster, Kacheln per Klick bemalen
        const tokenPattern = /\{(heart|red|orange|yellow|green|blue|violet|white|black|filled)\}|[^\n]/giu;
        const setDesigner = (on) => {
            target.classList.toggle('painting', on);
            scope.querySelectorAll('[data-designer-hint], [data-designer-only]').forEach((el) => { el.hidden = !on; });
            scope.querySelectorAll('[data-insert]').forEach((b) => b.classList.toggle('selected', on && b.dataset.insert === brush));
        };
        scope.querySelectorAll('[data-preview-align]').forEach((radio) => radio.addEventListener('change', () => {
            const align = currentAlign();
            if (align === 'grid' && lastAlign !== 'grid' && input.value.trim() !== '') {
                // Was gerade in der Vorschau steht, wird 1:1 ins Raster übernommen
                fetchPreview(input.value, lastAlign).then((data) => { input.value = data.exact; update(); }).catch(update);
            } else {
                update();
            }
            lastAlign = align;
            setDesigner(align === 'grid');
        }));
        target.addEventListener('click', (event) => {
            const cell = event.target.closest('[data-r]');
            if (!cell || currentAlign() !== 'grid') return;
            const r = Number(cell.dataset.r);
            const c = Number(cell.dataset.c);
            const lines = input.value.replace(/\r/g, '').split('\n');
            while (lines.length <= r) lines.push('');
            const units = lines[r].match(tokenPattern) ?? [];
            while (units.length <= c) units.push(' ');
            units[c] = units[c] === brush ? ' ' : brush; // gleiche Farbe nochmal = wieder leer
            lines[r] = units.join('').replace(/\s+$/u, '');
            input.value = lines.join('\n').replace(/\n+$/, '');
            update();
        });

        // Vestaboard-Kunst: Motiv in den Designer laden
        scope.querySelectorAll('[data-art]').forEach((button) => {
            button.addEventListener('click', () => {
                if (input.value.trim() !== '' && !window.confirm(tr('Aktuellen Text durch das Motiv ersetzen?'))) return;
                input.value = button.dataset.art;
                const align = button.dataset.align || 'grid';
                const radio = scope.querySelector(`[data-preview-align][value="${align}"]`);
                if (radio) radio.checked = true;
                lastAlign = align;
                setDesigner(align === 'grid');
                button.closest('details')?.removeAttribute('open');
                update();
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        scope.querySelector('[data-strip-colors]')?.addEventListener('click', () => {
            if (!/\{(red|orange|yellow|green|blue|violet|white|black|filled)\}/i.test(input.value)) return;
            if (!window.confirm(tr('Alle Farbkacheln aus dem Text entfernen?'))) return;
            // Im Designer werden Farbkacheln zu Leerfeldern (Positionen bleiben), sonst einfach entfernt
            const grid = currentAlign() === 'grid';
            input.value = input.value
                .replace(/\{(red|orange|yellow|green|blue|violet|white|black|filled)\}/gi, grid ? ' ' : '')
                .split('\n').map((line) => (grid ? line.replace(/\s+$/u, '') : line.replace(/ {2,}/g, ' ').trim())).join('\n')
                .replace(/\n+$/, '');
            input.focus();
            update();
        });

        scope.querySelectorAll('[data-insert]').forEach((button) => {
            button.addEventListener('click', () => {
                if (currentAlign() === 'grid') {
                    brush = button.dataset.insert;
                    setDesigner(true);
                    return;
                }
                const token = button.dataset.insert;
                const start = input.selectionStart ?? input.value.length;
                const end = input.selectionEnd ?? input.value.length;
                input.value = input.value.slice(0, start) + token + input.value.slice(end);
                input.focus();
                input.selectionStart = input.selectionEnd = start + token.length;
                update();
            });
        });
    };
    document.querySelectorAll('[data-preview-input]').forEach(initPreview);

    // Webhook-Vorlagen: Felder und Symbole gehen in das zuletzt benutzte Vorlagen-Feld des Formulars
    ['focusin', 'click', 'input', 'keyup'].forEach((type) => document.addEventListener(type, (event) => {
        if (event.target.matches?.('textarea[data-preview-input], textarea[data-token-target]') && event.target.form) event.target.form.lastTemplate = event.target;
    }));
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-insert-last]');
        if (!button) return;
        const form = button.closest('form');
        const input = form?.lastTemplate?.isConnected ? form.lastTemplate : form?.querySelector('textarea[data-preview-input], textarea[data-token-target]');
        if (!input) return;
        const token = button.dataset.insertLast;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.value = input.value.slice(0, start) + token + input.value.slice(end);
        input.focus();
        input.selectionStart = input.selectionEnd = start + token.length;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Eingebettete Felder (z. B. „in [__] Minuten“) wählen ihre Option automatisch mit aus
    document.querySelectorAll('label > input[type="radio"] ~ input, label > input[type="radio"] ~ select').forEach((field) => {
        const radio = field.parentElement.querySelector('input[type="radio"]');
        ['focus', 'input', 'change'].forEach((type) => field.addEventListener(type, () => {
            if (!radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }));
    });

    // Zurück-Button (bfcache): gesperrte Formulare wieder freigeben
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form[data-submitted]').forEach((form) => {
            delete form.dataset.submitted;
            form.querySelectorAll('button.is-busy').forEach((button) => {
                button.classList.remove('is-busy');
                button.disabled = 'locked' in button.dataset || (button.matches('[data-preview-submit]') && document.querySelector('[data-preview-counter]')?.classList.contains('over'));
            });
        });
    });

    // Wecker: gespeicherte Vorlage in Text + Ausrichtung übernehmen
    document.querySelectorAll('[data-fill-template]').forEach((select) => {
        select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            const form = select.closest('form');
            if (!option?.dataset.text || !form) return;
            const input = form.querySelector('[data-preview-input]');
            const radio = form.querySelector(`[data-preview-align][value="${option.dataset.align || 'center'}"]`);
            if (radio) radio.checked = true;
            input.value = option.dataset.text;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            select.value = '';
        });
    });

    // „Als Vorlage speichern“: Namen abfragen
    document.querySelectorAll('[data-template-save]').forEach((button) => {
        button.addEventListener('click', (event) => {
            const name = window.prompt(tr('Name der Vorlage:'), '');
            if (name === null) {
                event.preventDefault();
                return;
            }
            const field = button.form?.querySelector('[data-template-name]');
            if (field) field.value = name;
        });
    });

    // Schlüssel kopieren
    document.querySelectorAll('[data-copy-button]').forEach((button) => {
        button.addEventListener('click', async () => {
            // data-copy-button="…" kopiert den Wert direkt, sonst das [data-copy] in der nächsten Gruppe
            const text = button.dataset.copyButton || (button.closest('[data-copy-scope], section')?.querySelector('[data-copy]')?.textContent ?? '');
            const label = button.textContent;
            try {
                await navigator.clipboard.writeText(text.trim());
                button.textContent = tr('Kopiert ✓');
                setTimeout(() => { button.textContent = label; }, 2000);
            } catch (e) {
                button.textContent = tr('Bitte von Hand markieren und kopieren');
            }
        });
    });

    // Glocke: Enter im Stunden-Feld schaltet für diese Dauer stumm (nicht der erste Knopf „30 Min“)
    document.querySelectorAll('.quickmute-custom input').forEach((field) => field.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        field.form.requestSubmit(field.parentElement.querySelector('button'));
    }));

    // Druckansicht (Briefkasten-QR-Code)
    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

    // Seite regelmäßig neu laden (z. B. Warteschlange, während die eigene Nachricht wartet)
    document.querySelectorAll('[data-autorefresh]').forEach((el) => {
        setInterval(() => { if (!document.hidden) window.location.reload(); }, parseInt(el.dataset.autorefresh, 10) * 1000);
    });

    // Rangfolge per Pfeiltasten verschieben
    document.querySelectorAll('[data-order]').forEach((list) => {
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-move]');
            if (!button) return;
            const item = button.closest('li');
            if (button.dataset.move === 'up' && item.previousElementSibling) {
                list.insertBefore(item, item.previousElementSibling);
            } else if (button.dataset.move === 'down' && item.nextElementSibling) {
                list.insertBefore(item.nextElementSibling, item);
            }
            button.focus();
        });

        // Drag & Drop am Griff (Maus und Touch über Pointer-Events)
        let dragging = null;
        list.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('[data-drag-handle]');
            if (!handle) return;
            event.preventDefault();
            dragging = handle.closest('li');
            dragging.classList.add('dragging');
            handle.setPointerCapture(event.pointerId);
        });
        list.addEventListener('pointermove', (event) => {
            if (!dragging) return;
            const over = document.elementFromPoint(event.clientX, event.clientY)?.closest('li');
            if (!over || over === dragging || over.parentElement !== list) return;
            const box = over.getBoundingClientRect();
            list.insertBefore(dragging, event.clientY < box.top + box.height / 2 ? over : over.nextElementSibling);
        });
        const stop = () => {
            dragging?.classList.remove('dragging');
            dragging = null;
        };
        list.addEventListener('pointerup', stop);
        list.addEventListener('pointercancel', stop);
    });

    // Countdown für die Kinder-Pause; danach Abschicken freigeben (Entwurf bleibt erhalten)
    document.querySelectorAll('[data-countdown]').forEach((box) => {
        let seconds = parseInt(box.dataset.countdown, 10);
        const text = box.querySelector('[data-countdown-text]');
        let timer = null;
        const tick = () => {
            seconds -= 1;
            if (seconds <= 0) {
                clearInterval(timer);
                box.hidden = true;
                document.querySelectorAll('[data-preview-submit][data-locked]').forEach((button) => {
                    delete button.dataset.locked;
                    button.disabled = (document.querySelector('[data-preview-counter]')?.classList.contains('over')) ?? false;
                });
                return;
            }
            const minutes = Math.floor(seconds / 60);
            const rest = String(seconds % 60).padStart(2, '0');
            if (text) text.textContent = minutes >= 60 ? tr('{hours} Std {minutes} Min', { hours: Math.floor(minutes / 60), minutes: minutes % 60 }) : tr('{minutes}:{seconds} Min', { minutes, seconds: rest });
        };
        timer = setInterval(tick, 1000);
    });

    // Pill-Menü: zeigt nur den gewählten Bereich (Anker bleibt in der Adresse, z. B. nach dem Speichern)
    document.querySelectorAll('[data-tabs]').forEach((nav) => {
        const links = [...nav.querySelectorAll('a[href^="#"]')];
        const panels = links.map((a) => document.getElementById(a.hash.slice(1))).filter(Boolean);
        if (panels.length < 2) return;
        const show = (id) => {
            const active = panels.some((p) => p.id === id) ? id : panels[0].id;
            panels.forEach((p) => { p.hidden = p.id !== active; });
            links.forEach((a) => {
                const on = a.hash === `#${active}`;
                a.classList.toggle('active', on);
                if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
            });
        };
        links.forEach((a) => a.addEventListener('click', (event) => {
            event.preventDefault();
            history.replaceState(null, '', a.hash);
            show(a.hash.slice(1));
        }));
        show(location.hash.slice(1));
        // Sprung zum Anker würde das Menü wegscrollen – es soll sichtbar bleiben
        history.scrollRestoration = 'manual';
        const toTop = () => window.scrollTo(0, 0);
        toTop();
        window.addEventListener('load', () => setTimeout(toTop, 30));
        window.addEventListener('hashchange', () => show(location.hash.slice(1)));
    });

    // Automatisch speichern (Zufallspool): kurz nach jeder Änderung, ohne Seitenwechsel
    document.querySelectorAll('form[data-autosave]').forEach((form) => {
        const status = form.querySelector('[data-autosave-status]');
        let timer = null;
        let running = null;
        const save = () => {
            timer = null;
            const data = new FormData(form);
            data.set('action', form.dataset.autosave);
            data.set('autosave', '1');
            if (status) status.textContent = tr('Speichert …');
            running?.abort();
            running = new AbortController();
            fetch(form.getAttribute('action') || window.location.pathname, { method: 'POST', body: data, signal: running.signal })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((result) => { if (status) status.textContent = [tr('Gespeichert ✓'), ...(result.messages || [])].join(' · '); })
                .catch((error) => { if (error?.name !== 'AbortError' && status) status.textContent = tr('Nicht gespeichert – bitte „Speichern“ tippen.'); });
        };
        const later = (delay) => { clearTimeout(timer); timer = setTimeout(save, delay); };
        // normales Absenden (z. B. „+ Beispielsprüche“): kein späterer Beacon mit dem alten Stand
        form.addEventListener('submit', () => { clearTimeout(timer); timer = null; running?.abort(); });
        form.addEventListener('input', () => later(1200));
        form.addEventListener('change', () => later(300));
        form.addEventListener('click', (event) => { if (event.target.closest('[data-pool-remove], [data-slot-remove], [data-insert-last]')) later(300); });
        // Seite verlassen: offene Änderung noch schnell senden
        window.addEventListener('pagehide', () => { if (timer) { clearTimeout(timer); const data = new FormData(form); data.set('action', form.dataset.autosave); data.set('autosave', '1'); navigator.sendBeacon?.(form.getAttribute('action') || window.location.pathname, data); } });
    });

    // Zufallspool: Textfeld wächst mit den Zeilen, neue Sprüche anlegen, Zeilen entfernen
    const autosize = (field) => { field.rows = Math.max(1, Math.min(3, field.value.split('\n').length)); };
    document.addEventListener('input', (event) => {
        if (event.target.matches?.('[data-autosize]')) autosize(event.target);
    });
    document.querySelectorAll('[data-pool-list]').forEach((list) => {
        const fieldset = list.closest('fieldset');
        const template = fieldset.querySelector('[data-pool-template]');
        fieldset.querySelector('[data-pool-add]')?.addEventListener('click', () => {
            const html = template.innerHTML.replaceAll('__i__', `n${Date.now()}`);
            list.insertAdjacentHTML('beforeend', html);
            list.lastElementChild.querySelector('textarea')?.focus();
        });
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-pool-remove]');
            if (!button) return;
            const row = button.closest('[data-pool-row]');
            // Leeres Feld wird beim Speichern ignoriert; Zeile verschwindet sofort
            row.querySelector('textarea').value = '';
            row.hidden = true;
        });
    });

    // Umschalter zeigt das passende Feld-Paket ([data-mode-panel]) im selben Formular
    document.querySelectorAll('[data-mode-switch]').forEach((radio) => {
        radio.addEventListener('change', () => {
            radio.form.querySelectorAll('[data-mode-panel]').forEach((panel) => { panel.hidden = panel.dataset.modePanel !== radio.value; });
        });
    });

    // Zyklen (Tage + Uhrzeit): hinzufügen und entfernen; der letzte bleibt stehen
    document.querySelectorAll('[data-slots]').forEach((box) => {
        const list = box.querySelector('[data-slot-list]');
        const template = box.querySelector('[data-slot-template]');
        const sync = () => {
            const rows = list.querySelectorAll('[data-slot]');
            const min = parseInt(box.dataset.slotsMin ?? '1', 10);
            rows.forEach((row) => { row.querySelector('[data-slot-remove]').hidden = rows.length <= min; });
        };
        box.querySelector('[data-slot-add]')?.addEventListener('click', () => {
            list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__i__', `n${Date.now()}`));
            list.lastElementChild.querySelectorAll('[data-preview-input]').forEach(initPreview);
            sync();
            list.lastElementChild.querySelector('input')?.focus();
        });
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-slot-remove]');
            if (!button) return;
            button.closest('[data-slot]').remove();
            sync();
        });
        sync();
    });
})();
