/* Los 3 Chanchitos del Banco - rodillos, líneas, comodines expansivos y Candado y Carga. */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var SYM = window.SlotSymbols;
    var CW = 80;
    var CH = 80;
    var LINE_COLORS = ['#ffe08a', '#ff6b81', '#6dd5ff', '#8dff9a', '#ffb347', '#d6a6ff'];
    var board = null;
    var layout = null;
    var apiRef = null;
    var extras = [];

    function short(n) {
        if (n >= 1000000) {
            return (Math.round(n / 100000) / 10) + 'M';
        }
        if (n >= 10000) {
            return Math.round(n / 1000) + 'k';
        }
        return C.fmt(n);
    }

    function label(code, p) {
        if (p.base === 'L') {
            var jp = layout && layout.jackpots[String(p.value)];
            return { text: jp || short(apiRef.coins(p.value)) };
        }
        if (p.base === 'K' && p.value > 0) {
            return { text: short(apiRef.coins(p.value)) };
        }
        return null;
    }

    function clearExtras() {
        for (var i = 0; i < extras.length; i++) {
            if (extras[i].parentNode) {
                extras[i].parentNode.removeChild(extras[i]);
            }
        }
        extras = [];
    }

    function renderJackpots() {
        if (!layout || !apiRef) {
            return;
        }
        var box = document.getElementById('jackpots');
        var items = [['GRAND', layout.grand, 'grand']];
        var names = { MAJOR: 'major', MINOR: 'minor', MINI: 'mini' };
        var list = [];
        for (var v in layout.jackpots) {
            if (layout.jackpots.hasOwnProperty(v)) {
                list.push([layout.jackpots[v], parseFloat(v), names[layout.jackpots[v]] || 'mini']);
            }
        }
        list.sort(function (a, b) { return b[1] - a[1]; });
        items = items.concat(list);
        box.innerHTML = '';
        for (var i = 0; i < items.length; i++) {
            var row = C.el('div', items[i][2]);
            row.appendChild(C.el('span', '', items[i][0]));
            row.appendChild(C.el('span', '', short(apiRef.coins(items[i][1]))));
            box.appendChild(row);
        }
    }

    /** Animación de rodillos: cada rodillo gira y frena en orden sobre el resultado. */
    function spinReels(grid, api, done) {
        clearExtras();
        board.clear();
        var syms = layout.symbols;
        var last = layout.reels - 1;
        for (var c = 0; c < layout.reels; c++) {
            (function (c) {
                var strip = C.el('div', 'reelstrip spinning');
                strip.style.left = (c * CW) + 'px';
                var extra = 10 + c * 4;
                var codes = grid[c].slice();
                for (var i = 0; i < extra; i++) {
                    codes.push(syms[Math.floor(Math.random() * syms.length)]);
                }
                for (var k = 0; k < codes.length; k++) {
                    var cell = C.el('div', 'cell');
                    cell.style.left = '0px';
                    cell.style.top = (k * CH) + 'px';
                    cell.style.width = CW + 'px';
                    cell.style.height = CH + 'px';
                    var p = api.parse(codes[k]);
                    cell.innerHTML = SYM.img('chanchitos', p.base);
                    strip.appendChild(cell);
                }
                window.Slot.setTransform(strip, 'translate3d(0,' + (-extra * CH) + 'px,0)');
                board.layer.appendChild(strip);
                void strip.offsetWidth;
                var dur = api.speed(650 + c * 230);
                strip.style.webkitTransition = '-webkit-transform ' + dur + 'ms cubic-bezier(.25,.9,.35,1.04)';
                strip.style.transition = 'transform ' + dur + 'ms cubic-bezier(.25,.9,.35,1.04)';
                window.Slot.setTransform(strip, 'translate3d(0,0,0)');
                window.setTimeout(function () {
                    strip.className = 'reelstrip';
                    api.sfx('stop');
                    if (c === last) {
                        board.setGrid(grid);
                        done();
                    }
                }, dur);
            })(c);
        }
    }

    function showWilds(wilds, api, done) {
        if (!wilds.length) {
            done();
            return;
        }
        for (var i = 0; i < wilds.length; i++) {
            var w = wilds[i];
            for (var r = w.from; r < w.from + w.size; r++) {
                board.cell(w.reel, r).innerHTML = '';
            }
            var col = C.el('div', 'wildcol');
            col.innerHTML = SYM.img('chanchitos', 'W');
            col.style.left = (w.reel * CW + 3) + 'px';
            col.style.width = (CW - 6) + 'px';
            col.style.top = (w.landed * CH + 3) + 'px';
            col.style.height = (CH - 6) + 'px';
            board.el.appendChild(col);
            extras.push(col);
            (function (col, w) {
                void col.offsetWidth;
                col.style.top = (w.from * CH + 3) + 'px';
                col.style.height = (w.size * CH - 6) + 'px';
                if (w.size > 1) {
                    col.appendChild(C.el('b', '', 'x' + w.size));
                }
            })(col, w);
        }
        api.sfx('coin');
        api.wait(450, done);
    }

    function showLines(step, api, done) {
        if (!step.lines.length && !step.scatter) {
            done();
            return;
        }
        var svgNs = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('class', 'lines');
        svg.setAttribute('width', layout.reels * CW);
        svg.setAttribute('height', layout.rows * CH);
        var cells = [];
        for (var i = 0; i < step.lines.length; i++) {
            var l = step.lines[i];
            var rows = layout.lines[l.line];
            var pts = [];
            for (var c = 0; c < layout.reels; c++) {
                pts.push((c * CW + CW / 2) + ',' + (rows[c] * CH + CH / 2));
                if (c < l.count) {
                    cells.push([c, rows[c]]);
                }
            }
            var pl = document.createElementNS(svgNs, 'polyline');
            pl.setAttribute('points', pts.join(' '));
            pl.setAttribute('fill', 'none');
            pl.setAttribute('stroke', LINE_COLORS[i % LINE_COLORS.length]);
            pl.setAttribute('stroke-width', '4');
            pl.setAttribute('stroke-linejoin', 'round');
            pl.setAttribute('opacity', '0.9');
            svg.appendChild(pl);
            var end = board.centerOf([[l.count - 1, rows[l.count - 1]]]);
            api.floatWin(end.x, end.y, short(api.coins(l.win)) + (l.mult > 1 ? ' x' + l.mult : ''));
        }
        if (step.scatter) {
            for (var c2 = 0; c2 < layout.reels; c2++) {
                for (var r = 0; r < layout.rows; r++) {
                    if (board.grid[c2][r] === 'S') {
                        cells.push([c2, r]);
                    }
                }
            }
        }
        board.el.appendChild(svg);
        extras.push(svg);
        board.highlight(cells);
        api.panel('pSpin', C.fmt(api.coins(step.win)));
        api.wait(step.lines.length > 3 ? 1300 : 950, done);
    }

    function coinGrid(coins) {
        var g = [];
        for (var c = 0; c < layout.reels; c++) {
            g[c] = [];
            for (var r = 0; r < layout.rows; r++) {
                g[c][r] = '';
            }
        }
        for (var i = 0; i < coins.length; i++) {
            g[coins[i].reel][coins[i].row] = coins[i].k + coins[i].v;
        }
        return g;
    }

    function paintCoins(coins, fresh) {
        board.setGrid(coinGrid(coins));
        for (var c = 0; c < layout.reels; c++) {
            for (var r = 0; r < layout.rows; r++) {
                if (board.grid[c][r] === '') {
                    board.cell(c, r).className = 'cell empty';
                }
            }
        }
        if (fresh) {
            board.highlight(fresh);
        }
    }

    window.Slot.start({
        game: 'chanchitos',
        onInit: function (lay, api) {
            layout = lay;
            apiRef = api;
            board = new api.Board(api.stage, 'chanchitos', lay.reels, lay.rows, CW, CH, label);
            var g = [];
            for (var c = 0; c < lay.reels; c++) {
                g[c] = [];
                for (var r = 0; r < lay.rows; r++) {
                    g[c][r] = lay.symbols[(c * 2 + r * 3) % lay.symbols.length];
                }
            }
            board.setGrid(g);
            renderJackpots();
            window.setInterval(renderJackpots, 700);
        },
        play: function (res, api, done) {
            var stage = api.stage;
            var total = 0;
            var tasks = [];
            api.panel('pSpin', '0');
            res.steps.forEach(function (s) {
                tasks.push(function (next) {
                    switch (s.t) {
                        case 'spin':
                            if (s.fs) {
                                api.panel('pFs', s.fs.n + ' / ' + (s.fs.n + s.fs.left), true);
                            }
                            api.panel('pSpin', '0');
                            spinReels(s.grid, api, function () {
                                showWilds(s.wilds, api, function () {
                                    total += s.win;
                                    api.setWin(api.coins(total));
                                    showLines(s, api, next);
                                });
                            });
                            return;
                        case 'fs_start':
                            stage.className = 'stage fs';
                            api.sfx('bonus');
                            api.panel('pFs', s.spins, true);
                            api.banner('¡GIROS GRATIS!', s.spins + ' giros con comodines más grandes', 1800, next);
                            return;
                        case 'fs_retrigger':
                            api.sfx('bonus');
                            api.banner('+' + s.add + ' GIROS', null, 1200, next);
                            return;
                        case 'fs_end':
                            api.banner('FIN DE LOS GIROS GRATIS', 'Ganaste ' + C.fmt(api.coins(s.win)) + ' URU Coins', 1800, function () {
                                stage.className = 'stage';
                                api.panel('pFs', '-');
                                next();
                            });
                            return;
                        case 'hw_start':
                            clearExtras();
                            stage.className = 'stage hw';
                            api.sfx('bonus');
                            api.panel('pRespins', s.respins, true);
                            paintCoins(s.coins);
                            api.banner('CANDADO Y CARGA', 'Las monedas quedan trabadas: ' + s.respins + ' re-giros', 1900, next);
                            return;
                        case 'hw_collect':
                            paintCoins(s.coins, s.collects.map(function (k) { return [k.reel, k.row]; }));
                            api.sfx('coin');
                            api.banner('¡EL CHANCHITO JUNTA!', null, 1100, next);
                            return;
                        case 'hw_respin':
                            var fresh = s.new.map(function (k) { return [k.reel, k.row]; });
                            fresh = fresh.concat(s.collects.map(function (k) { return [k.reel, k.row]; }));
                            paintCoins(s.coins, fresh);
                            api.panel('pRespins', s.respins, true);
                            if (s.new.length) {
                                api.sfx('coin');
                            }
                            api.wait(s.new.length ? 900 : 650, next);
                            return;
                        case 'hw_end':
                            total += s.win;
                            api.setWin(api.coins(total));
                            api.banner(s.grand ? '¡¡GRAND!!' : 'CANDADO Y CARGA', 'Ganaste ' + C.fmt(api.coins(s.win)) + ' URU Coins', s.grand ? 2600 : 1900, function () {
                                stage.className = 'stage';
                                api.panel('pRespins', '-');
                                next();
                            });
                            return;
                    }
                    next();
                });
            });
            api.seq(tasks, done);
        }
    });
})(window, document);
