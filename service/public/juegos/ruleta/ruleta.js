/*
 * Ruleta francesa - página del MOTD.
 * El servidor valida todo: acá solo se arma la jugada y se reproducen los frames que llegan.
 */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var CELL_W = 26;
    var CELL_H = 35;
    var GRID_X = 30;
    var GRID_H = CELL_H * 3;
    var EDGE = 0.24;
    var WHEEL_HALF = 110;
    var TRACK = 0.93 * WHEEL_HALF;
    var ANNOUNCED = {
        vecinos_cero: { label: 'Vecinos del 0', units: 9 },
        tercio: { label: 'Tercio', units: 6 },
        huerfanos: { label: 'Huérfanos', units: 5 },
        juego_cero: { label: 'Juego del 0', units: 4 },
        vecinos: { label: 'Vecinos de...', units: 5 }
    };
    var SIMPLES = [
        { type: 'falta', label: '1-18' },
        { type: 'par', label: 'PAR' },
        { type: 'rojo', label: '' },
        { type: 'negro', label: '' },
        { type: 'impar', label: 'IMPAR' },
        { type: 'pasa', label: '19-36' }
    ];

    var red = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];
    var S = {
        balance: 0,
        chips: [],
        chip: 0,
        min: 10,
        max: 10000,
        bets: {},
        order: [],
        announced: [],
        last: null,
        busy: false,
        waitingStart: false,
        vecinosMode: false,
        frames: [],
        lastReceived: false,
        startAt: null,
        fi: 0,
        animating: false,
        pendingResult: null,
        scale: 1
    };

    function $(id) {
        return document.getElementById(id);
    }

    function isRed(n) {
        return red.indexOf(n) !== -1;
    }

    function colorOf(n) {
        return n === 0 ? 'verde' : (isRed(n) ? 'rojo' : 'negro');
    }

    function range(a, b, step) {
        var out = [];
        for (var i = a; i <= b; i += (step || 1)) {
            out.push(i);
        }
        return out;
    }

    /* ---------------------------------------------------------------------
     * Escalado del escenario a la ventana del MOTD
     * ------------------------------------------------------------------- */
    function fit() {
        var stage = $('stage');
        var s = Math.min(window.innerWidth / 640, window.innerHeight / 440);
        S.scale = s;
        var left = Math.max(0, (window.innerWidth - 640 * s) / 2);
        var top = Math.max(0, (window.innerHeight - 440 * s) / 2);
        var t = 'translate(' + left + 'px,' + top + 'px) scale(' + s + ')';
        stage.style.webkitTransform = t;
        stage.style.transform = t;
    }

    /* ---------------------------------------------------------------------
     * Mesa
     * ------------------------------------------------------------------- */
    function numberAt(c, r) {
        return 3 * c + 3 - r;
    }

    function center(n) {
        if (n === 0) {
            return { x: GRID_X / 2, y: GRID_H / 2 };
        }
        var c = Math.floor((n - 1) / 3);
        var r = 2 - ((n - 1) % 3);
        return { x: GRID_X + c * CELL_W + CELL_W / 2, y: r * CELL_H + CELL_H / 2 };
    }

    /** Qué apuesta corresponde a un clic en la grilla (coordenadas relativas a la grilla). */
    function pick(x, y) {
        var fx = x / CELL_W;
        var fy = y / CELL_H;
        var c = Math.max(0, Math.min(11, Math.floor(fx)));
        var r = Math.max(0, Math.min(2, Math.floor(fy)));
        var dx = fx - c;
        var dy = fy - r;
        var nearL = dx < EDGE;
        var nearR = dx > 1 - EDGE;
        var nearT = dy < EDGE;
        var nearB = dy > 1 - EDGE;
        var n = numberAt(c, r);

        if (r === 2 && nearB) {
            if (nearL && c === 0) {
                return { type: 'cuadro', numbers: [0, 1, 2, 3], street: true };
            }
            if (nearL || nearR) {
                var c0 = nearL ? c - 1 : c;
                if (c0 >= 0 && c0 < 11) {
                    return { type: 'seisena', numbers: range(3 * c0 + 1, 3 * c0 + 6), street: true };
                }
            }
            return { type: 'transversal', numbers: [3 * c + 1, 3 * c + 2, 3 * c + 3], street: true };
        }
        if ((nearL || nearR) && (nearT || nearB)) {
            var cc = nearL ? c - 1 : c;
            var rr = nearT ? r - 1 : r;
            if (rr >= 0 && rr + 1 <= 2) {
                if (cc < 0) {
                    return { type: 'transversal', numbers: [0, numberAt(0, rr + 1), numberAt(0, rr)].sort(num) };
                }
                if (cc + 1 <= 11) {
                    return { type: 'cuadro', numbers: [numberAt(cc, rr), numberAt(cc, rr + 1), numberAt(cc + 1, rr), numberAt(cc + 1, rr + 1)].sort(num) };
                }
            }
        }
        if (nearL || nearR) {
            var other = nearL ? c - 1 : c + 1;
            if (other < 0) {
                return { type: 'caballo', numbers: [0, n] };
            }
            if (other <= 11) {
                return { type: 'caballo', numbers: [n, numberAt(other, r)].sort(num) };
            }
        }
        if (nearT || nearB) {
            var orow = nearT ? r - 1 : r + 1;
            if (orow >= 0 && orow <= 2) {
                return { type: 'caballo', numbers: [n, numberAt(c, orow)].sort(num) };
            }
        }
        return { type: 'pleno', numbers: [n] };
    }

    function num(a, b) {
        return a - b;
    }

    /** Punto de la mesa donde se dibuja la ficha de una apuesta interior. */
    function anchor(spec) {
        var xs = 0;
        var ys = 0;
        var k = 0;
        var hasZero = false;
        for (var i = 0; i < spec.numbers.length; i++) {
            if (spec.numbers[i] === 0) {
                hasZero = true;
                continue;
            }
            var p = center(spec.numbers[i]);
            xs += p.x;
            ys += p.y;
            k++;
        }
        if (k === 0) {
            return center(0);
        }
        var pt = { x: xs / k, y: ys / k };
        if (hasZero) {
            pt.x = GRID_X;
        }
        if (spec.street) {
            pt.y = GRID_H;
        }
        return pt;
    }

    function outsideNumbers(type, which) {
        switch (type) {
            case 'docena': return range(12 * (which - 1) + 1, 12 * which);
            case 'columna': return range(which, 36, 3);
            case 'rojo': return red.slice();
            case 'negro': return range(1, 36).filter(function (n) { return !isRed(n); });
            case 'par': return range(2, 36, 2);
            case 'impar': return range(1, 35, 2);
            case 'falta': return range(1, 18);
            case 'pasa': return range(19, 36);
        }
        return [];
    }

    function buildTable() {
        var grid = $('grid');
        for (var c = 0; c < 12; c++) {
            for (var r = 0; r < 3; r++) {
                var n = numberAt(c, r);
                var cell = C.el('div', 'cell ' + colorOf(n));
                cell.id = 'n' + n;
                cell.style.left = (c * CELL_W) + 'px';
                cell.style.top = (r * CELL_H) + 'px';
                cell.appendChild(C.el('span', '', n));
                grid.appendChild(cell);
            }
        }
        grid.onclick = function (e) {
            var rect = grid.getBoundingClientRect();
            var x = (e.clientX - rect.left) / S.scale;
            var y = (e.clientY - rect.top) / S.scale;
            var spec = pick(x, y);
            if (S.vecinosMode && spec.type === 'pleno') {
                addAnnounced('vecinos', spec.numbers[0]);
                return;
            }
            addInside(spec);
        };
        $('zero').onclick = function () {
            if (S.vecinosMode) {
                addAnnounced('vecinos', 0);
                return;
            }
            addInside({ type: 'pleno', numbers: [0] });
        };

        var cols = $('cols');
        for (var row = 0; row < 3; row++) {
            (function (which, row) {
                var b = C.el('div', 'colbet', '2:1');
                b.onclick = function () {
                    addOutside('columna', which, { x: 342 + 22, y: row * CELL_H + CELL_H / 2 });
                };
                cols.appendChild(b);
            })(3 - row, row);
        }

        var dozens = $('dozens');
        var names = ['1ª 12', '2ª 12', '3ª 12'];
        for (var d = 1; d <= 3; d++) {
            (function (which) {
                var o = C.el('div', 'opt', names[which - 1]);
                o.id = 'docena:' + which;
                o.onclick = function () {
                    addOutside('docena', which, null);
                };
                dozens.appendChild(o);
            })(d);
        }

        var simples = $('simples');
        for (var s = 0; s < SIMPLES.length; s++) {
            (function (def) {
                var o = C.el('div', 'opt ' + (def.type === 'rojo' || def.type === 'negro' ? def.type + '-opt' : ''), def.label);
                if (!def.label) {
                    o.appendChild(C.el('div', 'dia'));
                }
                o.id = def.type;
                o.onclick = function () {
                    addOutside(def.type, 0, null);
                };
                simples.appendChild(o);
            })(SIMPLES[s]);
        }

        var ann = $('announced');
        for (var key in ANNOUNCED) {
            if (!ANNOUNCED.hasOwnProperty(key)) {
                continue;
            }
            (function (type) {
                var b = C.el('button', '', ANNOUNCED[type].label);
                b.id = 'ann-' + type;
                b.title = ANNOUNCED[type].units + ' fichas';
                b.onclick = function () {
                    if (type === 'vecinos') {
                        S.vecinosMode = !S.vecinosMode;
                        b.className = S.vecinosMode ? 'active' : '';
                        status(S.vecinosMode ? 'Elegí el número central' : '');
                        return;
                    }
                    addAnnounced(type, null);
                };
                ann.appendChild(b);
            })(key);
        }
    }

    /* ---------------------------------------------------------------------
     * Apuestas
     * ------------------------------------------------------------------- */
    function total() {
        var t = 0;
        for (var k in S.bets) {
            if (S.bets.hasOwnProperty(k)) {
                t += S.bets[k].amount;
            }
        }
        for (var i = 0; i < S.announced.length; i++) {
            t += S.announced[i].unit * ANNOUNCED[S.announced[i].type].units;
        }
        return t;
    }

    function canAdd(amount) {
        if (S.busy) {
            return false;
        }
        if (!S.chip) {
            C.toast('Elegí una ficha', 'error');
            return false;
        }
        var t = total() + amount;
        if (t > S.max) {
            C.toast('La apuesta máxima es ' + C.fmt(S.max), 'error');
            return false;
        }
        if (t > S.balance) {
            C.toast('No te alcanza el saldo', 'error');
            return false;
        }
        return true;
    }

    function addBet(key, spec, pos, host) {
        if (!canAdd(S.chip)) {
            return;
        }
        if (!S.bets[key]) {
            S.bets[key] = { spec: spec, amount: 0, pos: pos, host: host };
        }
        S.bets[key].amount += S.chip;
        S.order.push({ kind: 'bet', key: key, amount: S.chip });
        render();
    }

    function addInside(spec) {
        addBet(spec.type + ':' + spec.numbers.join('-'), spec, anchor(spec), null);
    }

    function addOutside(type, which, pos) {
        var key = which ? type + ':' + which : type;
        var spec = { type: type, numbers: outsideNumbers(type, which) };
        if (which) {
            spec.which = which;
        }
        addBet(key, spec, pos, pos ? null : key);
    }

    function addAnnounced(type, number) {
        var cost = S.chip * ANNOUNCED[type].units;
        if (!canAdd(cost)) {
            return;
        }
        var a = { type: type, unit: S.chip };
        if (type === 'vecinos') {
            a.number = number;
            S.vecinosMode = false;
            $('ann-vecinos').className = '';
            status('');
            C.toast('Vecinos del ' + number + ': 5 fichas de ' + C.fmt(S.chip));
        }
        S.announced.push(a);
        S.order.push({ kind: 'ann' });
        render();
    }

    function payload() {
        var out = [];
        for (var k in S.bets) {
            if (!S.bets.hasOwnProperty(k)) {
                continue;
            }
            var b = S.bets[k];
            var o = { type: b.spec.type, amount: b.amount };
            if (b.spec.which) {
                o.which = b.spec.which;
            } else if (['pleno', 'caballo', 'transversal', 'cuadro', 'seisena'].indexOf(b.spec.type) !== -1) {
                o.numbers = b.spec.numbers;
            }
            out.push(o);
        }
        for (var i = 0; i < S.announced.length; i++) {
            out.push(S.announced[i]);
        }
        return out;
    }

    function snapshot() {
        return JSON.parse(JSON.stringify({ bets: S.bets, announced: S.announced }));
    }

    function clearBets() {
        S.bets = {};
        S.announced = [];
        S.order = [];
        render();
    }

    /* ---------------------------------------------------------------------
     * Dibujo
     * ------------------------------------------------------------------- */
    function chipEl(amount) {
        var e = C.el('div', 'bet ' + C.chipClass(amount), C.chipLabel(amount));
        return e;
    }

    function render() {
        var layer = $('chipsLayer');
        layer.innerHTML = '';
        var opts = document.querySelectorAll('.opt .bet');
        for (var i = 0; i < opts.length; i++) {
            opts[i].parentNode.removeChild(opts[i]);
        }
        for (var k in S.bets) {
            if (!S.bets.hasOwnProperty(k)) {
                continue;
            }
            var b = S.bets[k];
            var chip = chipEl(b.amount);
            chip.setAttribute('data-key', k);
            if (b.host) {
                $(b.host).appendChild(chip);
            } else {
                chip.style.left = b.pos.x + 'px';
                chip.style.top = b.pos.y + 'px';
                layer.appendChild(chip);
            }
        }
        var counts = {};
        for (var j = 0; j < S.announced.length; j++) {
            counts[S.announced[j].type] = (counts[S.announced[j].type] || 0) + 1;
        }
        for (var type in ANNOUNCED) {
            if (!ANNOUNCED.hasOwnProperty(type)) {
                continue;
            }
            var btn = $('ann-' + type);
            var old = btn.querySelector('.count');
            if (old) {
                btn.removeChild(old);
            }
            if (counts[type]) {
                btn.appendChild(C.el('span', 'count', 'x' + counts[type]));
            }
        }
        $('balance').textContent = C.fmt(S.balance);
        $('total').textContent = C.fmt(total());
        $('limits').textContent = C.fmt(S.min) + ' - ' + C.fmt(S.max);
        $('spin').disabled = S.busy;
        $('undo').disabled = S.busy || S.order.length === 0;
        $('clear').disabled = S.busy || S.order.length === 0;
        $('repeat').disabled = S.busy || !S.last;
        $('double').disabled = S.busy || S.order.length === 0;
        $('stage').className = 'stage' + (S.busy ? ' locked' : '');
    }

    function renderChips() {
        var box = $('chips');
        box.innerHTML = '';
        if (S.chips.indexOf(S.chip) === -1) {
            S.chip = S.chips.length ? S.chips[0] : 0;
        }
        for (var i = 0; i < S.chips.length; i++) {
            (function (v) {
                var c = C.el('div', 'chip ' + C.chipClass(v) + (v === S.chip ? ' selected' : ''), C.chipLabel(v));
                c.title = C.fmt(v);
                c.onclick = function () {
                    S.chip = v;
                    renderChips();
                };
                box.appendChild(c);
            })(S.chips[i]);
        }
    }

    function renderHistory(list) {
        var box = $('history');
        box.innerHTML = '';
        for (var i = 0; i < list.length; i++) {
            box.appendChild(C.el('div', 'hist ' + colorOf(list[i]), list[i]));
        }
    }

    function status(text) {
        $('status').textContent = text;
    }

    function setResult(n, text, win) {
        var numEl = $('resultNum');
        numEl.textContent = n === null ? '-' : n;
        numEl.className = 'num' + (n === null ? '' : ' ' + colorOf(n));
        $('resultTxt').textContent = text;
        $('result').className = 'result' + (win ? ' win' : '');
    }

    /* ---------------------------------------------------------------------
     * Animación
     * ------------------------------------------------------------------- */
    function draw(w, b, r) {
        var wt = 'rotate(' + w + 'deg)';
        var wheel = $('wheel');
        wheel.style.webkitTransform = wt;
        wheel.style.transform = wt;
        var pt = 'rotate(' + b + 'deg)';
        var arm = $('pointerArm');
        arm.style.webkitTransform = pt;
        arm.style.transform = pt;
        var rad = b * Math.PI / 180;
        var R = r * TRACK;
        var ball = $('ball');
        ball.style.left = (WHEEL_HALF + R * Math.sin(rad)) + 'px';
        ball.style.top = (WHEEL_HALF - R * Math.cos(rad)) + 'px';
    }

    function tick() {
        if (!S.animating) {
            return;
        }
        var f = S.frames;
        if (!f.length) {
            C.raf(tick);
            return;
        }
        var t = C.now() - S.startAt;
        while (S.fi + 1 < f.length && f[S.fi + 1][0] <= t) {
            S.fi++;
        }
        var a = f[S.fi];
        var b = f[S.fi + 1];
        if (!b || t <= a[0]) {
            draw(a[1], a[2], a[3]);
        } else {
            var k = (t - a[0]) / (b[0] - a[0]);
            draw(a[1] + (b[1] - a[1]) * k, a[2] + (b[2] - a[2]) * k, a[3] + (b[3] - a[3]) * k);
        }
        if (S.lastReceived && S.fi === f.length - 1 && t >= f[f.length - 1][0]) {
            S.animating = false;
            if (S.pendingResult) {
                showResult(S.pendingResult);
            }
            return;
        }
        C.raf(tick);
    }

    function showResult(res) {
        S.pendingResult = null;
        S.busy = false;
        S.balance = res.balance;
        S.chips = res.chips;
        var n = res.number;
        var text;
        if (res.net > 0) {
            text = 'Salió el ' + n + '. ¡Ganaste ' + C.fmt(res.net) + '!';
        } else if (res.payout > 0) {
            text = 'Salió el ' + n + '. Recuperaste ' + C.fmt(res.payout) + '.';
        } else {
            text = 'Salió el ' + n + '. Perdiste ' + C.fmt(-res.net) + '.';
        }
        setResult(n, text, res.net > 0);
        renderHistory(res.history);
        var cell = n === 0 ? $('zero') : $('n' + n);
        cell.className += ' hit';
        window.setTimeout(function () {
            cell.className = cell.className.replace(' hit', '');
        }, 3200);

        var chips = document.querySelectorAll('.bet');
        for (var i = 0; i < chips.length; i++) {
            var key = chips[i].getAttribute('data-key');
            var bet = key && S.bets[key];
            if (bet) {
                chips[i].className += bet.spec.numbers.indexOf(n) !== -1 ? ' won' : ' lost';
            }
        }
        if (res.net > 0) {
            C.toast('¡Ganaste ' + C.fmt(res.net) + ' URU Coins!', 'win');
        }
        S.last = snapshot();
        renderChips();
        window.setTimeout(function () {
            if (!S.busy) {
                clearBets();
            }
        }, 2600);
        render();
    }

    /* ---------------------------------------------------------------------
     * Mensajes del servidor
     * ------------------------------------------------------------------- */
    function onMessage(m) {
        switch (m.type) {
            case 'init':
                S.balance = m.balance;
                S.chips = m.chips;
                S.min = m.min;
                S.max = m.max;
                if (m.red) {
                    red = m.red;
                }
                renderHistory(m.history || []);
                if (!S.animating) {
                    draw(m.wheel, 0, 1);
                }
                S.busy = !!m.busy;
                renderChips();
                render();
                if (S.busy) {
                    status('Esperando el giro anterior...');
                }
                break;
            case 'spin_start':
                S.waitingStart = false;
                S.balance = m.balance;
                S.frames = [];
                S.fi = 0;
                S.lastReceived = false;
                S.startAt = null;
                S.buffer = (m.buffer || 0.25) * 1000;
                S.pendingResult = null;
                $('ball').style.visibility = 'visible';
                setResult(null, 'No va más...', false);
                status('');
                render();
                break;
            case 'frames':
                for (var i = 0; i < m.f.length; i++) {
                    S.frames.push(m.f[i]);
                }
                if (m.last) {
                    S.lastReceived = true;
                }
                if (S.startAt === null) {
                    S.startAt = C.now() + S.buffer;
                    S.animating = true;
                    C.raf(tick);
                }
                break;
            case 'result':
                if (S.animating) {
                    S.pendingResult = m;
                } else {
                    showResult(m);
                }
                break;
            case 'notice':
                C.toast(m.message);
                break;
            case 'error':
                C.toast(m.message, 'error');
                if (S.waitingStart) {
                    S.waitingStart = false;
                    S.busy = false;
                    render();
                }
                break;
            case 'expired':
            case 'closed':
                C.overlay('La mesa se cerró. Cerrá esta ventana y escribí /ruleta en el chat para volver a jugar.');
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

    /* ---------------------------------------------------------------------
     * Arranque
     * ------------------------------------------------------------------- */
    fit();
    window.onresize = fit;
    buildTable();
    render();

    var transport = new C.Transport(onMessage, onStatus);

    $('spin').onclick = function () {
        if (S.busy) {
            return;
        }
        var t = total();
        if (t < S.min) {
            C.toast('La apuesta mínima es ' + C.fmt(S.min), 'error');
            return;
        }
        S.busy = true;
        S.waitingStart = true;
        render();
        transport.send({ type: 'spin', bets: payload() });
    };
    $('undo').onclick = function () {
        var last = S.order.pop();
        if (!last) {
            return;
        }
        if (last.kind === 'bet') {
            S.bets[last.key].amount -= last.amount;
            if (S.bets[last.key].amount <= 0) {
                delete S.bets[last.key];
            }
        } else {
            S.announced.pop();
        }
        render();
    };
    $('clear').onclick = clearBets;
    $('repeat').onclick = function () {
        if (!S.last || S.busy) {
            return;
        }
        var prev = { bets: S.bets, announced: S.announced, order: S.order };
        var snap = JSON.parse(JSON.stringify(S.last));
        S.bets = snap.bets;
        S.announced = snap.announced;
        S.order = [];
        for (var k in S.bets) {
            if (S.bets.hasOwnProperty(k)) {
                S.order.push({ kind: 'bet', key: k, amount: S.bets[k].amount });
            }
        }
        for (var i = 0; i < S.announced.length; i++) {
            S.order.push({ kind: 'ann' });
        }
        if (total() > S.balance || total() > S.max) {
            S.bets = prev.bets;
            S.announced = prev.announced;
            S.order = prev.order;
            C.toast('No te alcanza para repetir la jugada', 'error');
        }
        render();
    };
    $('double').onclick = function () {
        if (S.busy || total() * 2 > Math.min(S.balance, S.max)) {
            C.toast('No podés doblar: te pasás del saldo o del máximo', 'error');
            return;
        }
        for (var k in S.bets) {
            if (S.bets.hasOwnProperty(k)) {
                S.order.push({ kind: 'bet', key: k, amount: S.bets[k].amount });
                S.bets[k].amount *= 2;
            }
        }
        var n = S.announced.length;
        for (var i = 0; i < n; i++) {
            S.announced.push(JSON.parse(JSON.stringify(S.announced[i])));
            S.order.push({ kind: 'ann' });
        }
        render();
    };

    transport.start();
})(window, document);
