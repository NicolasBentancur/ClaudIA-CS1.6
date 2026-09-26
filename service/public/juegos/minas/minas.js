/*
 * Minas - página del MOTD. Las minas se sortean en el servidor y la página recién las ve
 * cuando termina la ronda; acá solo se arma la apuesta, se mandan los clics y se dibuja.
 */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var CELLS = 25;
    var PRESETS = [1, 3, 5, 10, 24];

    var S = {
        balance: 0,
        chips: [],
        bet: 0,
        lastBet: 0,
        min: 10,
        max: 10000,
        mines: 3,
        minMines: 1,
        maxMines: 24,
        rtp: 0.97,
        phase: 'betting',
        round: null,
        last: null,
        waiting: false,
        shown: [],
        init: true
    };

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

    function mult(x) {
        return 'x' + x.toFixed(2).replace('.', ',');
    }

    function has(list, v) {
        for (var i = 0; i < list.length; i++) {
            if (list[i] === v) {
                return true;
            }
        }
        return false;
    }

    /* ---------------- tablero ---------------- */

    var cells = [];

    function buildBoard() {
        var board = $('board');
        for (var i = 0; i < CELLS; i++) {
            (function (idx) {
                var c = C.el('div', 'cell hidden');
                c.onclick = function () {
                    reveal(idx);
                };
                board.appendChild(c);
                cells.push(c);
            })(i);
        }
    }

    function paint(idx, kind, extra, animate) {
        var c = cells[idx];
        c.innerHTML = '';
        if (kind === 'hidden') {
            c.className = 'cell hidden';
            return;
        }
        c.className = 'cell open ' + kind + (extra ? ' ' + extra : '') + (animate ? ' pop' : '');
        if (kind === 'star') {
            c.appendChild(document.createTextNode('★'));
        } else {
            c.appendChild(C.el('span', 'bomb'));
        }
    }

    function renderBoard() {
        var board = $('board');
        var live = S.phase === 'playing' && !S.waiting;
        board.className = 'board' + (live ? ' live' : '');
        var i;
        if (S.round) {
            var rev = S.round.revealed;
            for (i = 0; i < CELLS; i++) {
                if (has(rev, i)) {
                    paint(i, 'star', '', !has(S.shown, i));
                } else {
                    paint(i, 'hidden');
                }
            }
            S.shown = rev.slice(0);
            return;
        }
        if (S.phase === 'done' && S.last) {
            var L = S.last;
            for (i = 0; i < CELLS; i++) {
                var picked = has(L.revealed, i);
                var isMine = has(L.mines, i);
                var fresh = !S.init && !has(S.shown, i);
                if (isMine) {
                    paint(i, 'mine', i === L.hit ? 'hit' : (picked ? '' : 'ghost'), fresh && i === L.hit);
                } else {
                    paint(i, 'star', picked ? '' : 'ghost', fresh && picked);
                }
            }
            S.shown = [];
            return;
        }
        for (i = 0; i < CELLS; i++) {
            paint(i, 'hidden');
        }
        S.shown = [];
    }

    /* ---------------- panel ---------------- */

    function renderChips() {
        var box = $('chips');
        box.innerHTML = '';
        for (var i = 0; i < S.chips.length; i++) {
            (function (v) {
                var c = C.el('div', 'chip ' + C.chipClass(v), C.chipLabel(v));
                c.title = 'Sumar ' + C.fmt(v);
                c.onclick = function () {
                    addChip(v);
                };
                box.appendChild(c);
            })(S.chips[i]);
        }
    }

    function renderPresets() {
        var box = $('presets');
        box.innerHTML = '';
        for (var i = 0; i < PRESETS.length; i++) {
            (function (v) {
                if (v < S.minMines || v > S.maxMines) {
                    return;
                }
                var b = C.el('button', v === S.mines ? 'on' : '', v);
                b.disabled = !canBet();
                b.onclick = function () {
                    setMines(v);
                };
                box.appendChild(b);
            })(PRESETS[i]);
        }
    }

    function canBet() {
        return !S.waiting && S.phase !== 'playing';
    }

    function setMines(n) {
        if (!canBet()) {
            return;
        }
        S.mines = Math.max(S.minMines, Math.min(S.maxMines, n));
        renderPanel();
    }

    function addChip(v) {
        if (!canBet()) {
            return;
        }
        if (S.bet + v > S.max) {
            C.toast('La apuesta máxima es ' + C.fmt(S.max), 'error');
            return;
        }
        if (S.bet + v > S.balance) {
            C.toast('No te alcanza el saldo', 'error');
            return;
        }
        S.bet += v;
        renderPanel();
    }

    /* Multiplicador de la primera casilla (solo para mostrar antes de apostar). */
    function firstMult(mines) {
        return Math.floor(Math.round((CELLS / (CELLS - mines)) * S.rtp * 1e8) / 1e6) / 100;
    }

    function renderPanel() {
        $('minesCount').textContent = S.round ? S.round.mines : S.mines;
        $('bet').textContent = C.fmt(S.round ? S.round.bet : S.bet);
        $('balance').textContent = C.fmt(S.balance);
        var betting = canBet();
        $('minesDown').disabled = !betting || S.mines <= S.minMines;
        $('minesUp').disabled = !betting || S.mines >= S.maxMines;
        renderPresets();

        var main = $('main');
        if (S.round) {
            $('multNow').textContent = mult(S.round.multiplier);
            $('multNext').textContent = S.round.revealed.length >= CELLS - S.round.mines ? '-' : mult(S.round.next);
            $('winAmount').textContent = C.fmt(S.round.win);
            main.className = 'main cash';
            main.textContent = S.round.revealed.length ? 'COBRAR ' + C.fmt(S.round.win) : 'COBRAR';
            main.disabled = S.waiting || !S.round.revealed.length;
            $('random').disabled = S.waiting;
            $('clearBet').disabled = true;
        } else {
            $('multNow').textContent = 'x1,00';
            $('multNext').textContent = mult(firstMult(S.mines));
            $('winAmount').textContent = S.last && S.phase === 'done' ? C.fmt(S.last.payout) : '0';
            main.className = 'main';
            main.textContent = 'APOSTAR';
            main.disabled = !betting || S.bet < S.min || S.bet > S.balance;
            $('random').disabled = true;
            $('clearBet').disabled = !betting || S.bet === 0;
        }
    }

    function message(text, kind) {
        var m = $('message');
        m.textContent = text;
        m.className = 'message' + (kind ? ' ' + kind : '');
    }

    function render(st) {
        var wasPlaying = S.phase === 'playing';
        S.balance = st.balance;
        S.chips = st.chips;
        S.min = st.min;
        S.max = st.max;
        S.minMines = st.minMines;
        S.maxMines = st.maxMines;
        S.rtp = st.rtp || S.rtp;
        if (st.init) {
            S.mines = st.defaultMines;
        }
        S.phase = st.phase;
        S.round = st.round || null;
        S.last = st.last || null;
        S.waiting = false;
        S.init = !!st.init;

        if (S.round) {
            S.mines = S.round.mines;
            message(S.round.revealed.length
                ? 'Siguiente casilla: ' + mult(S.round.next) + '. ¿Seguís o cobrás?'
                : 'Destapá una casilla. Hay ' + S.round.mines + ' mina' + (S.round.mines === 1 ? '' : 's') + '.');
        } else if (S.phase === 'done' && S.last) {
            if (S.last.payout > 0) {
                message('¡Cobraste ' + C.fmt(S.last.payout) + ' (' + mult(S.last.multiplier) + ')!', 'win');
            } else {
                message('¡BOOM! Pisaste una mina. Perdiste ' + C.fmt(S.last.bet) + '.', 'lose');
            }
            if (wasPlaying) {
                S.bet = S.lastBet <= S.balance ? S.lastBet : 0;
            }
        } else {
            message('Elegí las minas, poné tu apuesta y jugá');
        }
        renderChips();
        renderBoard();
        renderPanel();
    }

    /* ---------------- acciones ---------------- */

    function send(obj) {
        S.waiting = true;
        renderBoard();
        renderPanel();
        transport.send(obj);
    }

    function reveal(idx) {
        if (S.waiting || S.phase !== 'playing' || !S.round || has(S.round.revealed, idx)) {
            return;
        }
        send({ type: 'reveal', cell: idx });
    }

    function onMessage(m) {
        switch (m.type) {
            case 'state':
                render(m);
                break;
            case 'notice':
                C.toast(m.message);
                break;
            case 'error':
                C.toast(m.message, 'error');
                S.waiting = false;
                renderBoard();
                renderPanel();
                break;
            case 'expired':
            case 'closed':
                C.overlay('El juego se cerró. Cerrá esta ventana y escribí /minas en el chat para volver a jugar.');
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
    buildBoard();
    var transport = new C.Transport(onMessage, onStatus);

    $('minesDown').onclick = function () {
        setMines(S.mines - 1);
    };
    $('minesUp').onclick = function () {
        setMines(S.mines + 1);
    };
    $('clearBet').onclick = function () {
        if (canBet()) {
            S.bet = 0;
            renderPanel();
        }
    };
    $('random').onclick = function () {
        if (!S.waiting && S.phase === 'playing') {
            send({ type: 'random' });
        }
    };
    $('main').onclick = function () {
        if (S.waiting) {
            return;
        }
        if (S.phase === 'playing') {
            if (S.round && S.round.revealed.length) {
                send({ type: 'cashout' });
            }
            return;
        }
        if (S.bet < S.min || S.bet > S.balance) {
            return;
        }
        S.lastBet = S.bet;
        S.shown = [];
        send({ type: 'bet', amount: S.bet, mines: S.mines });
    };

    renderBoard();
    renderPanel();
    transport.start();
})(window, document);
