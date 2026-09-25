/* Mate Rush - clusters, multiplicadores de casilla, rayos, chispazo y sincronización. */
(function (window) {
    'use strict';

    var C = window.Claudia;
    var CW = 60;
    var CH = 60;
    var board = null;
    var multEls = [];
    var multState = [];

    function label(code, p) {
        return p.base === 'R' ? { text: 'x' + p.value, center: true } : null;
    }

    function buildMults(cols, rows) {
        for (var c = 0; c < cols; c++) {
            multEls[c] = [];
            multState[c] = [];
            for (var r = 0; r < rows; r++) {
                var m = C.el('div', 'mult');
                m.style.left = (c * CW) + 'px';
                m.style.top = (r * CH) + 'px';
                m.style.width = CW + 'px';
                m.style.height = CH + 'px';
                board.el.appendChild(m);
                multEls[c][r] = m;
                multState[c][r] = 0;
            }
        }
    }

    /** Dibuja el estado de las casillas (0 nada, 1 marcada, 2+ multiplicador). */
    function setMults(state, api) {
        var max = 0;
        for (var c = 0; c < state.length; c++) {
            for (var r = 0; r < state[c].length; r++) {
                var v = state[c][r];
                var el = multEls[c][r];
                var changed = v !== multState[c][r];
                multState[c][r] = v;
                max = Math.max(max, v);
                el.className = 'mult' + (v >= 1 ? ' marked' : '') + (changed && v >= 2 ? ' bump' : '');
                el.innerHTML = v >= 2 ? '<b>x' + v + '</b>' : '';
            }
        }
        api.panel('pMax', max >= 2 ? 'x' + max : '-', max >= 32);
    }

    window.Slot.start({
        game: 'materush',
        onInit: function (layout, api) {
            board = new api.Board(api.stage, 'materush', layout.cols, layout.rows, CW, CH, label);
            var g = [];
            for (var c = 0; c < layout.cols; c++) {
                g[c] = [];
                for (var r = 0; r < layout.rows; r++) {
                    g[c][r] = layout.symbols[(c * 2 + r * 3) % layout.symbols.length];
                }
            }
            board.setGrid(g);
            buildMults(layout.cols, layout.rows);
        },
        play: function (res, api, done) {
            var stage = api.stage;
            var total = 0;
            var tasks = [];
            api.panel('pSpin', '0');
            api.panel('pRush', '-');
            res.steps.forEach(function (s) {
                tasks.push(function (next) {
                    switch (s.t) {
                        case 'fs_start':
                            stage.className = 'stage fs';
                            setMults(s.cells, api);
                            api.sfx('bonus');
                            api.panel('pFs', s.spins, true);
                            api.banner('¡GIROS GRATIS!', s.spins + ' giros: los multiplicadores no se borran', 1800, next);
                            return;
                        case 'drop':
                            api.panel('pSpin', '0');
                            api.panel('pRush', '-');
                            if (s.fs) {
                                api.panel('pFs', s.fs.n + ' / ' + (s.fs.n + s.fs.left), true);
                            }
                            setMults(s.cells, api);
                            board.dropIn(s.grid, api, next);
                            return;
                        case 'electric':
                            api.sfx('rush');
                            board.setGrid(s.grid);
                            for (var i = 0; i < s.cells.length; i++) {
                                board.cell(s.cells[i][0], s.cells[i][1]).className = 'cell zap';
                            }
                            api.banner('¡CHISPAZO!', null, 1000, next);
                            return;
                        case 'pay':
                            var cells = [];
                            s.wins.forEach(function (w) {
                                cells = cells.concat(w.cells);
                                var ctr = board.centerOf(w.cells);
                                api.floatWin(ctr.x, ctr.y, C.fmt(api.coins(w.win)) + (w.mult > 1 ? ' x' + w.mult : ''));
                            });
                            board.highlight(cells);
                            api.panel('pSpin', C.fmt(api.coins(s.total)));
                            api.sfx('win');
                            api.wait(750, function () {
                                setMults(s.cells, api);
                                next();
                            });
                            return;
                        case 'tumble':
                            board.explode(s.removed, api, function () {
                                board.tumble(s.grid, s.removed, api, next);
                            });
                            return;
                        case 'rush':
                            board.highlight(s.rush.map(function (b) { return [b.col, b.row]; }));
                            api.sfx('rush');
                            api.panel('pRush', 'x' + s.sum, true);
                            api.panel('pSpin', C.fmt(api.coins(s.after)), true);
                            api.banner('RAYO x' + s.sum, C.fmt(api.coins(s.before)) + ' → ' + C.fmt(api.coins(s.after)), 1300, next);
                            return;
                        case 'sync':
                            api.sfx('rush');
                            setMults(s.state, api);
                            api.banner('SINCRONIZACIÓN', s.cells.length + ' casillas pasan a x' + s.value, 1300, next);
                            return;
                        case 'spin_end':
                            total += s.win;
                            api.setWin(api.coins(total));
                            api.wait(s.win > 0 ? 250 : 80, next);
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
                    }
                    next();
                });
            });
            api.seq(tasks, done);
        }
    });
})(window);
