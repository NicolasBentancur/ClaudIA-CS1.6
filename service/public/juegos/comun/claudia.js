/*
 * Claudia - transporte común de los juegos por MOTD.
 * Intenta WebSocket; si no conecta en 2,5 s (o el navegador no lo soporta) usa polling HTTP.
 * Escrito en ES5 para funcionar en navegadores viejos del MOTD.
 */
(function (window) {
    'use strict';

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1]) : '';
    }

    /*
     * Errores de JS -> log del servicio. El MOTD del juego es un Chrome 18 sin consola a mano:
     * es la única forma de enterarse de qué falla ahí adentro.
     */
    var errorsSent = 0;
    window.onerror = function (message, source, line) {
        var token = param('t');
        if (!token || errorsSent >= 5 || !window.XMLHttpRequest) {
            return false;
        }
        errorsSent++;
        try {
            var x = new window.XMLHttpRequest();
            x.open('POST', '/api/send?t=' + encodeURIComponent(token), true);
            x.setRequestHeader('Content-Type', 'application/json');
            x.send(JSON.stringify({
                type: 'jserror',
                message: String(message),
                source: String(source || '').replace(/\?.*$/, ''),
                line: line || 0,
                ua: navigator.userAgent
            }));
        } catch (e) {
            // Nada que hacer.
        }
        return false;
    };

    function Transport(onMessage, onStatus) {
        this.token = param('t');
        this.wsUrl = param('ws');
        this.onMessage = onMessage;
        this.onStatus = onStatus || function () {};
        this.seq = 0;
        this.ws = null;
        this.wsOpen = false;
        this.polling = false;
        this.pollTimer = null;
        this.closed = false;
        this.failures = 0;
    }

    Transport.prototype.start = function () {
        var self = this;
        if (!this.token) {
            this.onStatus('error', 'Falta el token. Abrí el juego desde el servidor.');
            return;
        }
        if (window.WebSocket && this.wsUrl) {
            try {
                this.ws = new window.WebSocket(this.wsUrl);
            } catch (e) {
                this.ws = null;
            }
        }
        if (!this.ws) {
            this.startPolling();
            return;
        }
        var fallback = window.setTimeout(function () {
            if (!self.wsOpen) {
                self.dropWs();
                self.startPolling();
            }
        }, 2500);
        this.ws.onopen = function () {
            window.clearTimeout(fallback);
            self.wsOpen = true;
            self.onStatus('ok', 'ws');
            self.ws.send(JSON.stringify({ type: 'hello', token: self.token }));
        };
        this.ws.onmessage = function (ev) {
            var msg;
            try {
                msg = JSON.parse(ev.data);
            } catch (e) {
                return;
            }
            self.handle(msg);
        };
        this.ws.onclose = function () {
            window.clearTimeout(fallback);
            var wasOpen = self.wsOpen;
            self.wsOpen = false;
            self.ws = null;
            if (!self.closed) {
                if (wasOpen) {
                    self.onStatus('warn', 'Se cortó la conexión, reconectando...');
                }
                self.startPolling();
            }
        };
    };

    Transport.prototype.dropWs = function () {
        if (this.ws) {
            this.ws.onclose = null;
            try {
                this.ws.close();
            } catch (e) {}
            this.ws = null;
        }
    };

    Transport.prototype.startPolling = function () {
        if (this.polling || this.closed) {
            return;
        }
        this.polling = true;
        this.onStatus('ok', 'poll');
        this.post({ type: 'hello' });
        this.poll();
    };

    Transport.prototype.poll = function () {
        var self = this;
        if (this.closed) {
            return;
        }
        var xhr = new window.XMLHttpRequest();
        xhr.open('GET', '/api/poll?t=' + encodeURIComponent(this.token) + '&since=' + this.seq + '&_=' + new Date().getTime(), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var delay = 120;
            if (xhr.status === 200) {
                self.failures = 0;
                try {
                    var res = JSON.parse(xhr.responseText);
                    for (var i = 0; i < res.messages.length; i++) {
                        self.handle(res.messages[i]);
                    }
                } catch (e) {}
            } else if (xhr.status === 410) {
                self.handle({ type: 'expired' });
                return;
            } else {
                self.failures++;
                delay = Math.min(3000, 300 * self.failures);
                if (self.failures > 3) {
                    self.onStatus('warn', 'Problemas de conexión...');
                }
            }
            self.pollTimer = window.setTimeout(function () {
                self.poll();
            }, delay);
        };
        xhr.send(null);
    };

    Transport.prototype.post = function (obj) {
        var self = this;
        var xhr = new window.XMLHttpRequest();
        xhr.open('POST', '/api/send?t=' + encodeURIComponent(this.token), true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 410) {
                self.handle({ type: 'expired' });
            }
        };
        xhr.send(JSON.stringify(obj));
    };

    Transport.prototype.handle = function (msg) {
        if (msg.seq) {
            if (msg.seq <= this.seq) {
                return; // duplicado (cambio de transporte)
            }
            this.seq = msg.seq;
        }
        if (msg.type === 'expired' || msg.type === 'closed') {
            this.closed = true;
            this.dropWs();
            window.clearTimeout(this.pollTimer);
        }
        this.onMessage(msg);
    };

    Transport.prototype.send = function (obj) {
        if (this.closed) {
            return;
        }
        if (this.wsOpen && this.ws) {
            this.ws.send(JSON.stringify(obj));
        } else {
            this.post(obj);
        }
    };

    function fmt(n) {
        var s = String(Math.abs(Math.round(n)));
        var out = '';
        while (s.length > 3) {
            out = '.' + s.slice(-3) + out;
            s = s.slice(0, -3);
        }
        return (n < 0 ? '-' : '') + s + out;
    }

    function chipLabel(v) {
        if (v >= 1000) {
            return (v % 1000 === 0 ? v / 1000 : (v / 1000).toFixed(1)) + 'k';
        }
        return String(v);
    }

    function chipClass(v) {
        if (v >= 5000) { return 'chip-5'; }
        if (v >= 1000) { return 'chip-4'; }
        if (v >= 250) { return 'chip-3'; }
        if (v >= 50) { return 'chip-2'; }
        if (v >= 25) { return 'chip-1'; }
        return 'chip-0';
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (text !== undefined && text !== null) {
            e.appendChild(document.createTextNode(String(text)));
        }
        return e;
    }

    function toast(text, kind) {
        var box = document.getElementById('toast');
        if (!box) {
            return;
        }
        box.className = 'toast show ' + (kind || '');
        box.textContent = text;
        window.clearTimeout(toast.timer);
        toast.timer = window.setTimeout(function () {
            box.className = 'toast';
        }, 3200);
    }

    function overlay(text) {
        var o = document.getElementById('overlay');
        if (o) {
            o.textContent = text;
            o.className = 'overlay show';
        }
    }

    var nativeRaf = window.requestAnimationFrame || window.webkitRequestAnimationFrame;

    // Hay que llamarlo con window como "this" (si no, tira "Illegal invocation").
    function raf(cb) {
        if (nativeRaf) {
            return nativeRaf.call(window, cb);
        }
        return window.setTimeout(function () {
            cb(new Date().getTime());
        }, 16);
    }

    function now() {
        return window.performance && window.performance.now ? window.performance.now() : new Date().getTime();
    }

    window.Claudia = {
        Transport: Transport,
        fmt: fmt,
        chipLabel: chipLabel,
        chipClass: chipClass,
        el: el,
        toast: toast,
        overlay: overlay,
        raf: raf,
        now: now
    };
})(window);
