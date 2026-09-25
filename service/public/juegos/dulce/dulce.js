/* Dulce de Leche Bonanza - animación de los pasos que manda el servidor. */
(function (window) {
    'use strict';

    var C = window.Claudia;
    var board = null;

    function codeCells(grid, pred) {
        var out = [];
        for (var c = 0; c < grid.length; c++) {
            for (var r = 0; r < grid[c].length; r++) {
                if (pred(grid[c][r])) {
                    out.push([c, r]);
                }
            }
        }
        return out;
    }

    window.Slot.start({
        game: 'dulce',
        onInit: function (layout, api) {
            board = new api.Board(api.stage, 'dulce', layout.cols, layout.rows, 62, 62, function (code, p) {
                return p.base === 'X' ? { text: 'x' + p.value, center: true } : null;
            });
            var syms = layout.symbols;
            var grid = [];
            for (var c = 0; c < layout.cols; c++) {
                grid[c] = [];
                for (var r = 0; r < layout.rows; r++) {
                    grid[c][r] = syms[(c * 3 + r * 2) % syms.length];
                }
            }
            board.setGrid(grid);
        },
        play: function (res, api, done) {
            var total = 0;
            var spinWin = 0;
            var tasks = [];
            var stage = api.stage;
            api.panel('pSpin', '0');
            api.panel('pBombs', '-');

            res.steps.forEach(function (s) {
                tasks.push(function (next) {
                    switch (s.t) {
                        case 'fs_start':
                            stage.className = 'stage fs';
                            api.sfx('bonus');
                            api.panel('pFs', s.spins, true);
                            api.banner('¡GIROS GRATIS!', s.spins + ' giros con bombones multiplicadores', 1800, next);
                            return;
                        case 'drop':
                            spinWin = 0;
                            api.panel('pSpin', '0');
                            if (s.fs) {
                                api.panel('pFs', s.fs.n + ' / ' + (s.fs.n + s.fs.left), true);
                                api.panel('pBombs', '-');
                            }
                            board.dropIn(s.grid, api, next);
                            return;
                        case 'pay':
                            var cells = [];
                            s.wins.forEach(function (w) {
                                cells = cells.concat(w.cells);
                                var ctr = board.centerOf(w.cells);
                                api.floatWin(ctr.x, ctr.y, C.fmt(api.coins(w.win)));
                            });
                            board.highlight(cells);
                            spinWin = s.total;
                            api.panel('pSpin', C.fmt(api.coins(spinWin)));
                            api.sfx('win');
                            api.wait(750, next);
                            return;
                        case 'tumble':
                            board.explode(s.removed, api, function () {
                                board.tumble(s.grid, s.removed, api, next);
                            });
                            return;
                        case 'bombs':
                            var bc = s.bombs.map(function (b) { return [b.col, b.row]; });
                            board.highlight(bc);
                            api.sfx('bomb');
                            api.panel('pBombs', 'x' + s.sum, true);
                            api.panel('pSpin', C.fmt(api.coins(s.after)), true);
                            api.banner('x' + s.sum, C.fmt(api.coins(s.before)) + ' → ' + C.fmt(api.coins(s.after)), 1300, next);
                            return;
                        case 'scatter':
                            board.highlight(codeCells(board.grid, function (x) { return x === 'S'; }));
                            api.sfx('bonus');
                            api.wait(700, next);
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
