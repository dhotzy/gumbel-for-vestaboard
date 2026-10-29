// Gewähltes Farbschema (Hell/Dunkel) vor dem ersten Zeichnen setzen – eigene Datei, damit die CSP keine Inline-Skripte braucht
try { const t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
