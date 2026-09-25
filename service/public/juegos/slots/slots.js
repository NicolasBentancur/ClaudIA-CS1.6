/*
 * Claudia - base común de los slots (ES5).
 *
 * Slot.start({ game, onInit(layout, api), play(result, api, done), label(code) })
 *   - onInit: arma el tablero cuando llega "init".
 *   - play:   anima result.steps y llama done() al terminar.
 *   - label:  texto que se dibuja sobre un símbolo especial (ej. "x10"), o null.
 *
 * Board: grilla genérica [col][fila] con caída, explosión y cascada.
 */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var SYM = window.SlotSymbols;

    // Escala del escenario y lado de la celda más grande: define a qué tamaño se rasterizan los símbolos.
    var view = { scale: 1, cell: 0 };

    function warmSymbols() {
        if (view.cell) {
            SYM.warm(view.cell * 0.88 * view.scale * (window.devicePixelRatio || 1));
        }
    }

    function $(id) {
        return document.getElementById(id);
    }

    /** Ejecuta funciones fn(next) en orden. */
    function seq(list, done) {
        var i = 0;
        function next() {
            if (i >= list.length) {
                if (done) {
                    done();
                }
                return;
            }
            var fn = list[i++];
            fn(next);
        }
        next();
    }

    function parse(code) {
        var m = /^([A-Z])(\d+(?:\.\d+)?)?$/.exec(code);
        if (m) {
            return { base: m[1], value: m[2] !== undefined ? parseFloat(m[2]) : null };
        }
        return { base: code, value: null };
    }

    function setTransform(el, t) {
        el.style.webkitTransform = t;
        el.style.transform = t;
    }

    /* =====================================================================
     * Board: grilla con cascadas
     * =================================================================== */
    function Board(parent, game, cols, rows, cw, ch, label) {
        this.game = game;
        this.cols = cols;
        this.rows = rows;
        this.cw = cw;
        this.ch = ch;
        this.label = label || function () { return null; };
        view.cell = Math.max(view.cell, cw, ch);
        warmSymbols();
        this.el = C.el('div', 'board');
        this.el.style.width = (cols * cw) + 'px';
        this.el.style.height = (rows * ch) + 'px';
        this.el.style.marginLeft = (-cols * cw / 2) + 'px';
        parent.appendChild(this.el);
        this.layer = C.el('div', '');
        this.el.appendChild(this.layer);
        this.cells = [];
        this.grid = [];
    }

    Board.prototype.makeCell = function (c, r, code) {
        var el = C.el('div', 'cell');
        el.style.left = (c * this.cw) + 'px';
        el.style.top = (r * this.ch) + 'px';
        el.style.width = this.cw + 'px';
        el.style.height = this.ch + 'px';
        this.paint(el, code);
        this.layer.appendChild(el);
        return el;
    };

    Board.prototype.paint = function (el, code) {
        var p = parse(code);
        el.innerHTML = SYM.img(this.game, p.base);
        el.setAttribute('data-code', code);
        var lab = this.label(code, p);
        if (lab) {
            el.appendChild(C.el('div', 'val' + (lab.center ? ' center' : ''), lab.text));
        }
    };

    Board.prototype.clear = function () {
        this.layer.innerHTML = '';
        this.cells = [];
    };

    Board.prototype.setGrid = function (grid) {
        this.clear();
        this.grid = grid;
        for (var c = 0; c < this.cols; c++) {
            this.cells[c] = [];
            for (var r = 0; r < this.rows; r++) {
                this.cells[c][r] = this.makeCell(c, r, grid[c][r]);
            }
        }
    };

    Board.prototype.cell = function (c, r) {
        return this.cells[c] && this.cells[c][r];
    };

    /** Caen todos los símbolos nuevos (columna por columna). */
    Board.prototype.dropIn = function (grid, api, done) {
        var self = this;
        var prev = this.cells.slice();
        var dist = this.rows * this.ch + 20;
        for (var c = 0; c < prev.length; c++) {
            for (var r = 0; r < (prev[c] || []).length; r++) {
                var old = prev[c][r];
                old.className = 'cell move';
                setTransform(old, 'translate3d(0,' + dist + 'px,0)');
            }
        }
        window.setTimeout(function () {
            self.setGrid(grid);
            var all = [];
            for (var c2 = 0; c2 < self.cols; c2++) {
                for (var r2 = 0; r2 < self.rows; r2++) {
                    var el = self.cells[c2][r2];
                    setTransform(el, 'translate3d(0,' + (-dist) + 'px,0)');
                    all.push({ el: el, c: c2 });
                }
            }
            void self.el.offsetWidth;
            var step = api.speed(70);
            for (var i = 0; i < all.length; i++) {
                (function (item) {
                    window.setTimeout(function () {
                        item.el.className = 'cell move';
                        setTransform(item.el, 'translate3d(0,0,0)');
                    }, item.c * step);
                })(all[i]);
            }
            for (var k = 0; k < self.cols; k++) {
                (function (col) {
                    window.setTimeout(function () {
                        if (col === self.cols - 1 || col % 2 === 0) {
                            api.sfx('stop');
                        }
                    }, col * step + 250);
                })(k);
            }
            window.setTimeout(done, self.cols * step + api.speed(360));
        }, prev.length ? api.speed(160) : 0);
    };

    Board.prototype.highlight = function (cells) {
        for (var i = 0; i < cells.length; i++) {
            var el = this.cell(cells[i][0], cells[i][1]);
            if (el) {
                el.className = 'cell win';
            }
        }
    };

    Board.prototype.explode = function (cells, api, done) {
        for (var i = 0; i < cells.length; i++) {
            var el = this.cell(cells[i][0], cells[i][1]);
            if (el) {
                el.className = 'cell boom';
            }
        }
        api.sfx('pop');
        window.setTimeout(done, api.speed(260));
    };

    /** Aplica una cascada: saca "removed", los de arriba caen y entran los nuevos de "grid". */
    Board.prototype.tumble = function (grid, removed, api, done) {
        var gone = {};
        for (var i = 0; i < removed.length; i++) {
            gone[removed[i][0] + ':' + removed[i][1]] = true;
        }
        var oldCells = this.cells;
        this.clear();
        this.grid = grid;
        var moves = [];
        for (var c = 0; c < this.cols; c++) {
            this.cells[c] = [];
            var kept = [];
            for (var r = 0; r < this.rows; r++) {
                if (!gone[c + ':' + r]) {
                    kept.push(r);
                }
            }
            var fresh = this.rows - kept.length;
            for (var r2 = 0; r2 < this.rows; r2++) {
                var el = this.makeCell(c, r2, grid[c][r2]);
                this.cells[c][r2] = el;
                var from = r2 < fresh ? (r2 - fresh) : kept[r2 - fresh];
                var dy = (from - r2) * this.ch;
                if (r2 < fresh) {
                    dy -= 10;
                }
                if (dy !== 0) {
                    setTransform(el, 'translate3d(0,' + dy + 'px,0)');
                    moves.push(el);
                }
            }
        }
        void this.el.offsetWidth;
        for (var m = 0; m < moves.length; m++) {
            moves[m].className = 'cell move';
            setTransform(moves[m], 'translate3d(0,0,0)');
        }
        oldCells = null;
        window.setTimeout(done, api.speed(380));
    };

    Board.prototype.centerOf = function (cells) {
        var x = 0;
        var y = 0;
        for (var i = 0; i < cells.length; i++) {
            x += cells[i][0] * this.cw + this.cw / 2;
            y += cells[i][1] * this.ch + this.ch / 2;
        }
        return { x: this.el.offsetLeft + x / cells.length, y: this.el.offsetTop + y / cells.length };
    };

    /* =====================================================================
     * Juego
     * =================================================================== */
    function start(opts) {
        var S = {
            balance: 0,
            bets: [],
            betIndex: 0,
            buy: [],
            busy: false,
            turbo: false,
            maxWin: 0,
            bet: 0
        };
        var stage = $('stage');

        function fit() {
            var s = Math.min(window.innerWidth / 640, window.innerHeight / 440);
            var left = Math.max(0, (window.innerWidth - 640 * s) / 2);
            var top = Math.max(0, (window.innerHeight - 440 * s) / 2);
            setTransform(stage, 'translate(' + left + 'px,' + top + 'px) scale(' + s + ')');
            view.scale = s;
            warmSymbols();
        }
        fit();
        window.onresize = fit;

        var transport;

        var api = {
            stage: stage,
            seq: seq,
            parse: parse,
            Board: Board,
            speed: function (ms) {
                return S.turbo ? Math.round(ms * 0.45) : ms;
            },
            wait: function (ms, fn) {
                window.setTimeout(fn, api.speed(ms));
            },
            sfx: function (name) {
                transport.send({ type: 'sfx', name: name });
            },
            coins: function (multiple) {
                return Math.floor(multiple * S.bet);
            },
            bet: function () {
                return S.bet;
            },
            setWin: function (coins) {
                $('lastwin').textContent = C.fmt(coins);
            },
            banner: function (t1, t2, ms, done) {
                var b = $('banner');
                b.innerHTML = '';
                b.appendChild(C.el('span', 't1', t1));
                if (t2) {
                    b.appendChild(C.el('span', 't2', t2));
                }
                b.className = 'banner show';
                window.setTimeout(function () {
                    b.className = 'banner';
                    if (done) {
                        window.setTimeout(done, 200);
                    }
                }, api.speed(ms || 1400));
            },
            floatWin: function (x, y, text) {
                var f = C.el('div', 'floatwin', text);
                f.style.left = (x - 40) + 'px';
                f.style.top = (y - 12) + 'px';
                f.style.width = '80px';
                f.style.textAlign = 'center';
                stage.appendChild(f);
                void f.offsetWidth;
                setTransform(f, 'translateY(-40px)');
                f.style.opacity = '0';
                window.setTimeout(function () {
                    if (f.parentNode) {
                        f.parentNode.removeChild(f);
                    }
                }, 1100);
            },
            panel: function (id, value, hot) {
                var p = $(id);
                if (!p) {
                    return;
                }
                p.querySelector('b').textContent = value;
                p.className = 'panel' + (hot ? ' hot' : '');
            }
        };

        function render() {
            $('balance').textContent = C.fmt(S.balance);
            S.bet = S.bets[S.betIndex] || 0;
            $('betAmount').textContent = C.fmt(S.bet);
            $('betDown').disabled = S.busy || S.betIndex <= 0;
            $('betUp').disabled = S.busy || S.betIndex >= S.bets.length - 1;
            $('spin').disabled = S.busy || S.bet > S.balance || !S.bet;
            $('buyBtn').disabled = S.busy || !S.buy.length;
            $('turbo').className = 'turbo' + (S.turbo ? ' on' : '');
        }

        function renderBuy() {
            var box = $('buyList');
            box.innerHTML = '';
            for (var i = 0; i < S.buy.length; i++) {
                (function (o) {
                    var cost = Math.round(o.price * S.bet);
                    var row = C.el('div', 'opt');
                    var info = C.el('span', '', o.name);
                    info.appendChild(C.el('small', '', 'Cuesta ' + C.fmt(cost) + ' (x' + o.price + ' la apuesta)'));
                    var b = C.el('button', 'primary', 'Comprar');
                    b.disabled = cost > S.balance;
                    b.onclick = function () {
                        $('buybox').className = 'buybox';
                        play({ type: 'buy', bet: S.bet, option: o.id });
                    };
                    row.appendChild(info);
                    row.appendChild(b);
                    box.appendChild(row);
                })(S.buy[i]);
            }
        }

        function play(msg) {
            if (S.busy) {
                return;
            }
            S.busy = true;
            render();
            api.setWin(0);
            api.sfx('spin');
            transport.send(msg);
        }

        function finish(res) {
            S.balance = res.balance;
            $('balance').textContent = C.fmt(S.balance);
            api.setWin(res.payout);
            var end = function () {
                S.busy = false;
                render();
            };
            // "Gran premio" solo si se ganó bastante y más de lo que costó (en una compra de bonus puede no ser así).
            if (res.payout > res.cost && res.multiple >= 20) {
                api.sfx('bigwin');
                api.banner(res.multiple >= 100 ? '¡MEGA PREMIO!' : '¡GRAN PREMIO!', C.fmt(res.payout) + ' URU Coins (x' + (Math.round(res.multiple * 10) / 10) + ')' + (res.capped ? ' - máximo alcanzado' : ''), 2600, end);
            } else if (res.payout > 0) {
                api.sfx('win');
                end();
            } else {
                end();
            }
        }

        function onMessage(m) {
            switch (m.type) {
                case 'init':
                    S.balance = m.balance;
                    S.bets = m.bets;
                    S.buy = m.buy || [];
                    S.maxWin = m.maxWin;
                    if (S.betIndex >= S.bets.length || !S.bet) {
                        S.betIndex = Math.max(0, Math.min(S.bets.length - 1, S.bets.indexOf(100)));
                    }
                    document.title = m.name + ' - Claudia';
                    $('title').textContent = m.name;
                    if (!api.initialized) {
                        api.initialized = true;
                        opts.onInit(m.layout, api);
                    }
                    render();
                    break;
                case 'result':
                    // Mientras se anima, el saldo muestra lo apostado ya descontado.
                    $('balance').textContent = C.fmt(S.balance - m.cost);
                    opts.play(m, api, function () {
                        finish(m);
                    });
                    break;
                case 'notice':
                    C.toast(m.message);
                    break;
                case 'error':
                    C.toast(m.message, 'error');
                    S.busy = false;
                    render();
                    break;
                case 'expired':
                case 'closed':
                    C.overlay('La máquina se cerró. Cerrá esta ventana y volvé a abrirla desde el chat.');
                    break;
            }
        }

        $('betDown').onclick = function () {
            if (S.betIndex > 0) {
                S.betIndex--;
                render();
            }
        };
        $('betUp').onclick = function () {
            if (S.betIndex < S.bets.length - 1) {
                S.betIndex++;
                render();
            }
        };
        $('spin').onclick = function () {
            play({ type: 'spin', bet: S.bet });
        };
        $('turbo').onclick = function () {
            S.turbo = !S.turbo;
            render();
        };
        $('buyBtn').onclick = function () {
            renderBuy();
            $('buybox').className = 'buybox show';
        };
        $('buyClose').onclick = function () {
            $('buybox').className = 'buybox';
        };
        document.onkeydown = function (e) {
            if ((e.keyCode === 32 || e.keyCode === 13) && !S.busy) {
                e.preventDefault();
                play({ type: 'spin', bet: S.bet });
            }
        };

        transport = new C.Transport(onMessage, function (kind, text) {
            if (kind === 'error') {
                C.overlay(text);
            } else if (kind === 'warn') {
                C.toast(text, 'error');
            }
        });
        render();
        transport.start();
        return api;
    }

    window.Slot = { start: start, Board: Board, seq: seq, parse: parse, setTransform: setTransform };
})(window, document);
