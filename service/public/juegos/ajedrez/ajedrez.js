/*
 * Ajedrez - página del MOTD. Las reglas, el reloj y la plata están en el servidor: acá se
 * dibuja el tablero, se eligen las jugadas (clic en la pieza y clic en el destino) y se
 * manejan el chat, el micrófono, las pistas, las tablas y la rendición.
 * ES5 y sin flexbox: el MOTD de CS 1.6 es un Chromium viejo.
 */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var GLYPH = { k: '♚', q: '♛', r: '♜', b: '♝', n: '♞', p: '♟' };
    var QUICK = ['¡Suerte!', 'Buena jugada', 'Uy...', 'Pensá rápido', 'GG'];
    var FILES = 'abcdefgh';

    var S = {
        st: null,          // último estado del servidor
        sel: null,         // casilla elegida ("e2")
        waiting: false,
        clock: { w: 0, b: 0 },
        clockAt: 0,
        resultShown: false,
        pendingMove: null
    };
    var cells = {};        // "e2" -> elemento de la casilla

    function $(id) {
        return document.getElementById(id);
    }

    function fit() {
        var stage = $('stage');
        var s = Math.min(window.innerWidth / 640, window.innerHeight / 440);
        var left = Math.max(0, (window.innerWidth - 640 * s) / 2);
        var top = Math.max(0, (window.innerHeight - 440 * s) / 2);
        var t = 'translate(' + left + 'px,' + top + 'px) scale(' + s + ')';
        stage.style.webkitTransform = t;
        stage.style.transform = t;
    }

    function sqName(idx) {
        return FILES.charAt(idx % 8) + (Math.floor(idx / 8) + 1);
    }

    function colorOf(p) {
        return !p ? '' : (p === p.toUpperCase() ? 'w' : 'b');
    }

    function pieceEl(p) {
        return C.el('span', 'pc ' + colorOf(p), GLYPH[p.toLowerCase()]);
    }

    /* ---------------- tablero ---------------- */

    /** Arma las 64 casillas en el orden de quien mira (negras = tablero dado vuelta). */
    function buildBoard(you) {
        var board = $('board');
        board.innerHTML = '';
        cells = {};
        for (var row = 0; row < 8; row++) {
            for (var col = 0; col < 8; col++) {
                var rank = you === 'b' ? row : 7 - row;
                var file = you === 'b' ? 7 - col : col;
                var name = FILES.charAt(file) + (rank + 1);
                var el = C.el('div', 'sq ' + ((rank + file) % 2 === 1 ? 'light' : 'dark'));
                el.setAttribute('data-sq', name);
                (function (n) {
                    el.onclick = function () {
                        clickSquare(n);
                    };
                })(name);
                cells[name] = el;
                board.appendChild(el);
            }
        }
        board.setAttribute('data-you', you);
    }

    function renderBoard() {
        var st = S.st;
        if ($('board').getAttribute('data-you') !== st.you) {
            buildBoard(st.you);
        }
        var mine = !st.over && st.turn === st.you && !S.waiting;
        $('board').className = 'board' + (mine ? ' mine' : '');
        var targets = S.sel && st.legal ? (st.legal[S.sel] || []) : [];
        for (var i = 0; i < 64; i++) {
            var name = sqName(i);
            var el = cells[name];
            var p = st.board[i];
            var file = i % 8;
            var rank = Math.floor(i / 8);
            var cls = 'sq ' + ((rank + file) % 2 === 1 ? 'light' : 'dark');
            if (st.last && (st.last.from === name || st.last.to === name)) {
                cls += ' last';
            }
            if (S.sel === name) {
                cls += ' sel';
            }
            if (st.check === name) {
                cls += ' check';
            }
            if (p && colorOf(p) === st.you) {
                cls += ' own';
            }
            var isTarget = false;
            for (var t = 0; t < targets.length; t++) {
                if (targets[t] === name) {
                    isTarget = true;
                }
            }
            if (isTarget || (S.sel && !st.legal)) {
                cls += ' target';
            }
            el.className = cls;
            el.innerHTML = '';
            // Coordenadas en el borde, como en chess.com.
            var leftCol = st.you === 'b' ? file === 7 : file === 0;
            var bottomRow = st.you === 'b' ? rank === 7 : rank === 0;
            if (leftCol) {
                el.appendChild(C.el('span', 'coord rank', rank + 1));
            }
            if (bottomRow) {
                el.appendChild(C.el('span', 'coord file', FILES.charAt(file)));
            }
            if (p) {
                el.appendChild(pieceEl(p));
            }
            if (isTarget) {
                el.appendChild(C.el('span', p ? 'ring' : 'dot'));
            }
        }
    }

    function clickSquare(name) {
        var st = S.st;
        if (!st || st.over || S.waiting || st.turn !== st.you) {
            return;
        }
        var idx = FILES.indexOf(name.charAt(0)) + (parseInt(name.charAt(1), 10) - 1) * 8;
        var p = st.board[idx];
        if (p && colorOf(p) === st.you) {
            S.sel = S.sel === name ? null : name;
            renderBoard();
            return;
        }
        if (!S.sel) {
            return;
        }
        var from = S.sel;
        if (st.legal) {
            var ok = false;
            var list = st.legal[from] || [];
            for (var i = 0; i < list.length; i++) {
                if (list[i] === name) {
                    ok = true;
                }
            }
            if (!ok) {
                S.sel = null;
                renderBoard();
                return;
            }
        }
        var fromIdx = FILES.indexOf(from.charAt(0)) + (parseInt(from.charAt(1), 10) - 1) * 8;
        var piece = st.board[fromIdx];
        var lastRank = st.you === 'w' ? '8' : '1';
        if (piece && piece.toLowerCase() === 'p' && name.charAt(1) === lastRank) {
            askPromotion(from, name);
            return;
        }
        sendMove(from, name, '');
    }

    function sendMove(from, to, promo) {
        S.sel = null;
        S.waiting = true;
        renderBoard();
        transport.send({ type: 'move', from: from, to: to, promo: promo });
    }

    function askPromotion(from, to) {
        var box = $('promoChoices');
        box.innerHTML = '';
        var you = S.st.you;
        var list = ['q', 'r', 'b', 'n'];
        for (var i = 0; i < list.length; i++) {
            (function (t) {
                var el = pieceEl(you === 'w' ? t.toUpperCase() : t);
                el.onclick = function () {
                    $('promo').className = 'modal';
                    sendMove(from, to, t);
                };
                box.appendChild(el);
            })(list[i]);
        }
        $('promo').className = 'modal show';
    }

    /* ---------------- reloj ---------------- */

    function fmtClock(ms) {
        ms = Math.max(0, ms);
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        var rest = s % 60;
        if (ms < 20000) {
            return '0:' + (rest < 10 ? '0' : '') + rest + '.' + Math.floor((ms % 1000) / 100);
        }
        return m + ':' + (rest < 10 ? '0' : '') + rest;
    }

    function tickClock() {
        var st = S.st;
        if (!st) {
            return;
        }
        var elapsed = C.now() - S.clockAt;
        var top = st.you === 'w' ? 'b' : 'w';
        var sides = [[top, 'Top'], [st.you, 'Bottom']];
        for (var i = 0; i < 2; i++) {
            var color = sides[i][0];
            var ms = S.clock[color] - (st.clock.running === color ? elapsed : 0);
            var el = $('clock' + sides[i][1]);
            el.textContent = fmtClock(ms);
            el.className = 'clock' + (ms < 30000 ? ' low' : '');
            $('bar' + sides[i][1]).className = 'pbar ' + (i === 0 ? 'top' : 'bottom') + (st.clock.running === color ? ' turn' : '');
        }
    }

    /* ---------------- panel ---------------- */

    function renderMoves() {
        var box = $('moves');
        box.innerHTML = '';
        var mv = S.st.moves;
        for (var i = 0; i < mv.length; i += 2) {
            box.appendChild(C.el('span', 'num', (i / 2 + 1) + '.'));
            box.appendChild(C.el('span', 'mv' + (i === mv.length - 1 ? ' cur' : ''), mv[i]));
            if (i + 1 < mv.length) {
                box.appendChild(C.el('span', 'mv' + (i + 1 === mv.length - 1 ? ' cur' : ''), mv[i + 1]));
            }
            box.appendChild(C.el('br'));
        }
        box.scrollTop = box.scrollHeight;
    }

    function renderPanel() {
        var st = S.st;
        var top = st.you === 'w' ? 'b' : 'w';
        $('nameTop').textContent = st.names[top];
        $('nameBottom').textContent = st.names[st.you] + ' (vos)';
        $('swTop').className = 'swatch ' + top;
        $('swBottom').className = 'swatch ' + st.you;
        $('prize').textContent = C.fmt(st.prize);
        $('balance').textContent = C.fmt(st.balance);

        var mic = $('mic');
        mic.className = 'mic' + (st.voice ? ' on' : '');
        mic.lastChild.nodeValue = st.voice ? 'Hablando...' : 'Micrófono';
        mic.disabled = st.over;

        var hint = $('hint');
        hint.textContent = st.hint ? 'Pistas activas' : 'Pistas (' + C.fmt(st.hintPrice) + ')';
        hint.className = st.hint ? 'hinted' : '';
        hint.disabled = st.hint || st.over;

        var draw = $('draw');
        draw.textContent = st.draw === 'out' ? 'Tablas ofrecidas' : '½ Tablas';
        draw.disabled = st.over || st.draw === 'out' || (st.draw !== 'in' && st.drawLeft <= 0);
        $('drawBar').className = 'drawbar' + (st.draw === 'in' && !st.over ? ' show' : '');
        $('resign').disabled = st.over;
    }

    function showResult() {
        var r = S.st.result;
        if (!r || S.resultShown) {
            return;
        }
        S.resultShown = true;
        var title = r.winner === null ? 'Tablas' : (r.winner === S.st.you ? '¡Ganaste!' : 'Perdiste');
        $('resultTitle').textContent = title;
        $('resultText').textContent = r.text;
        var net = $('resultNet');
        if (r.net > 0) {
            net.textContent = '+' + C.fmt(r.net) + ' URU Coins';
            net.className = 'net win';
        } else if (r.net < 0) {
            net.textContent = '-' + C.fmt(-r.net) + ' URU Coins';
            net.className = 'net lose';
        } else {
            net.textContent = 'Recuperás tu apuesta';
            net.className = 'net';
        }
        $('result').className = 'modal show';
    }

    function render(st) {
        var first = !S.st;
        S.st = st;
        S.waiting = false;
        S.clock = { w: st.clock.w, b: st.clock.b };
        S.clockAt = C.now();
        if (st.turn !== st.you || st.over) {
            S.sel = null;
        }
        if (first) {
            $('overlay').className = 'overlay';
        }
        renderBoard();
        renderMoves();
        renderPanel();
        tickClock();
        if (st.over) {
            showResult();
        }
    }

    /* ---------------- chat ---------------- */

    function addChat(line) {
        var log = $('chatLog');
        var el;
        if (line.system) {
            el = C.el('div', 'ln sys', line.text);
        } else {
            el = C.el('div', 'ln' + (line.own ? ' own' : ''));
            el.appendChild(C.el('b', '', line.from + ': '));
            el.appendChild(document.createTextNode(line.text));
        }
        log.appendChild(el);
        while (log.childNodes.length > 60) {
            log.removeChild(log.firstChild);
        }
        log.scrollTop = log.scrollHeight;
    }

    function sendChat(text) {
        text = String(text || '').replace(/^\s+|\s+$/g, '');
        if (text && S.st && !S.st.over) {
            transport.send({ type: 'chat', text: text });
        }
    }

    function buildQuick() {
        var box = $('quick');
        for (var i = 0; i < QUICK.length; i++) {
            (function (t) {
                var b = C.el('button', '', t);
                b.onclick = function () {
                    sendChat(t);
                };
                box.appendChild(b);
            })(QUICK[i]);
        }
    }

    /* ---------------- mensajes ---------------- */

    function onMessage(m) {
        switch (m.type) {
            case 'state':
                render(m);
                break;
            case 'chat':
                addChat(m);
                break;
            case 'chatlog':
                $('chatLog').innerHTML = '';
                for (var i = 0; i < m.lines.length; i++) {
                    addChat(m.lines[i]);
                }
                break;
            case 'nomatch':
                C.overlay('No estás jugando ninguna partida. Desafiá a alguien con /ajedrez <nick> <apuesta>.');
                break;
            case 'notice':
                C.toast(m.message);
                break;
            case 'error':
                C.toast(m.message, 'error');
                S.waiting = false;
                if (S.st) {
                    renderBoard();
                }
                break;
            case 'expired':
            case 'closed':
                if (!S.st || !S.st.over) {
                    C.overlay('El tablero se cerró. Escribí /ajedrez volver en el chat para abrirlo de nuevo.');
                }
                break;
        }
    }

    function onStatus(kind, text) {
        if (kind === 'error') {
            C.overlay(text);
        } else if (kind === 'warn') {
            C.toast(text, 'error');
        }
    }

    fit();
    window.onresize = fit;
    buildQuick();
    var transport = new C.Transport(onMessage, onStatus);

    $('chatInput').onkeydown = function (e) {
        e = e || window.event;
        if (e.keyCode === 13) {
            sendChat(this.value);
            this.value = '';
            return false;
        }
        return true;
    };
    $('mic').onclick = function () {
        if (S.st && !S.st.over) {
            transport.send({ type: 'voice', on: !S.st.voice });
        }
    };
    $('hint').onclick = function () {
        if (S.st && !S.st.hint && !S.st.over) {
            transport.send({ type: 'hint' });
        }
    };
    $('draw').onclick = function () {
        if (S.st && !S.st.over) {
            transport.send({ type: S.st.draw === 'in' ? 'draw_accept' : 'draw' });
        }
    };
    $('drawYes').onclick = function () {
        transport.send({ type: 'draw_accept' });
    };
    $('drawNo').onclick = function () {
        transport.send({ type: 'draw_decline' });
    };
    $('resign').onclick = function () {
        if (!S.st || S.st.over) {
            return;
        }
        $('confirmText').textContent = '¿Te rendís? Perdés ' + C.fmt(S.st.bet) + ' URU Coins.';
        $('confirm').className = 'modal show';
    };
    $('confirmYes').onclick = function () {
        $('confirm').className = 'modal';
        transport.send({ type: 'resign' });
    };
    $('confirmNo').onclick = function () {
        $('confirm').className = 'modal';
    };
    $('resultClose').onclick = function () {
        $('result').className = 'modal';
    };

    window.setInterval(tickClock, 100);
    buildBoard('w');
    transport.start();
})(window, document);
