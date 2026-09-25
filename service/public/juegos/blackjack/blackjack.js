/*
 * Blackjack - página del MOTD. Toda la lógica y el mazo están en el servidor;
 * acá se arma la apuesta, se mandan las acciones y se dibuja el estado que llega.
 */
(function (window, document) {
    'use strict';

    var C = window.Claudia;
    var SUITS = { s: '♠', h: '♥', d: '♦', c: '♣' };
    var RESULT_TEXT = { win: 'GANA', blackjack: 'BLACKJACK', push: 'EMPATE', lose: 'PIERDE', bust: 'SE PASÓ' };

    var S = {
        balance: 0,
        chips: [],
        bet: 0,
        lastBet: 0,
        min: 10,
        max: 10000,
        phase: 'betting',
        actions: {},
        shown: { dealer: 0, hands: [] },
        waiting: false
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

    function cardEl(card, isNew, delay) {
        var e;
        if (card.r === '?') {
            e = C.el('div', 'card back');
        } else {
            var red = card.s === 'h' || card.s === 'd';
            e = C.el('div', 'card' + (red ? ' red' : ''));
            var sym = SUITS[card.s] || '';
            var tl = C.el('div', 'corner');
            tl.innerHTML = card.r + '<br>' + sym;
            var br = C.el('div', 'corner br');
            br.innerHTML = card.r + '<br>' + sym;
            e.appendChild(tl);
            e.appendChild(C.el('div', 'pip', sym));
            e.appendChild(br);
        }
        if (isNew) {
            e.className += ' deal';
            e.style.webkitAnimationDelay = delay + 's';
            e.style.animationDelay = delay + 's';
        }
        return e;
    }

    function valueText(value, soft, final) {
        if (soft && value <= 21 && value !== 21 && !final) {
            return (value - 10) + '/' + value;
        }
        return String(value);
    }

    function renderBetSpot() {
        var spot = $('betspot');
        spot.innerHTML = '';
        if (S.bet > 0) {
            spot.appendChild(C.el('div', 'chip ' + C.chipClass(S.bet), C.chipLabel(S.bet)));
        } else {
            spot.appendChild(C.el('span', '', 'APUESTA'));
        }
        $('bet').textContent = C.fmt(S.bet);
    }

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

    function canBet() {
        return !S.waiting && (S.phase === 'betting' || S.phase === 'done');
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
        renderBetSpot();
        renderButtons();
    }

    function renderButtons() {
        var a = S.actions || {};
        var betting = canBet();
        $('hit').disabled = S.waiting || !a.hit;
        $('stand').disabled = S.waiting || !a.stand;
        $('double').disabled = S.waiting || !a.double;
        $('split').disabled = S.waiting || !a.split;
        $('deal').disabled = !betting || S.bet < S.min;
        $('clearBet').disabled = !betting || S.bet === 0;
        $('rebet').disabled = !betting || !S.lastBet || S.lastBet > S.balance;
    }

    function message(text, kind) {
        var m = $('message');
        m.textContent = text;
        m.className = 'message' + (kind ? ' ' + kind : '');
    }

    function render(st) {
        S.balance = st.balance;
        S.chips = st.chips;
        S.min = st.min;
        S.max = st.max;
        S.phase = st.phase;
        S.actions = st.actions;
        S.waiting = false;
        $('balance').textContent = C.fmt(st.balance);
        if (st.rules) {
            $('rulesText').textContent = 'EL CRUPIER ' + (st.rules.soft17 === 'pide' ? 'PIDE' : 'SE PLANTA') + ' EN 17 BLANDO · ' + st.rules.decks + ' MAZOS';
        }

        // Crupier
        var dc = $('dealerCards');
        dc.innerHTML = '';
        for (var i = 0; i < st.dealer.cards.length; i++) {
            var isNew = !st.init && i >= S.shown.dealer;
            dc.appendChild(cardEl(st.dealer.cards[i], isNew, isNew ? (i - S.shown.dealer) * 0.25 : 0));
        }
        S.shown.dealer = st.dealer.cards.length;
        $('dealerValue').textContent = st.dealer.cards.length ? (st.dealer.blackjack ? 'BJ' : st.dealer.value) : '';

        // Jugador
        var hands = $('hands');
        hands.innerHTML = '';
        var prevHands = S.shown.hands;
        var nextShown = [];
        for (var h = 0; h < st.hands.length; h++) {
            var hand = st.hands[h];
            var box = C.el('div', 'hand' + (st.phase === 'player' && h === st.active && st.hands.length > 1 ? ' active' : ''));
            var cards = C.el('div', 'cards');
            var before = st.init ? hand.cards.length : (prevHands[h] || 0);
            if (prevHands.length === 1 && st.hands.length === 2 && h > 0) {
                before = 0; // mano nueva del split
            }
            for (var c = 0; c < hand.cards.length; c++) {
                var fresh = c >= before;
                cards.appendChild(cardEl(hand.cards[c], fresh, fresh ? (c - before) * 0.25 : 0));
            }
            nextShown.push(hand.cards.length);
            box.appendChild(cards);
            var info = C.el('div', 'info');
            info.appendChild(C.el('span', 'value', valueText(hand.value, hand.soft, hand.done)));
            info.appendChild(document.createTextNode(' ' + C.fmt(hand.bet) + (hand.doubled ? ' (x2)' : '')));
            if (hand.result) {
                info.appendChild(C.el('span', 'res ' + hand.result, RESULT_TEXT[hand.result] || hand.result));
            }
            box.appendChild(info);
            hands.appendChild(box);
        }
        S.shown.hands = nextShown;
        if (!st.hands.length) {
            S.shown = { dealer: 0, hands: [] };
        }

        if (st.phase === 'player') {
            message(st.hands.length > 1 ? 'Mano ' + (st.active + 1) + ': ¿pedís o te plantás?' : '¿Pedís o te plantás?');
        } else if (st.phase === 'dealer') {
            message('Juega el crupier...');
        } else if (st.phase === 'done' && st.hands.length) {
            if (st.dealer.blackjack && st.net < 0) {
                message('El crupier tiene blackjack. Perdiste ' + C.fmt(-st.net) + '.', 'lose');
            } else if (st.net > 0) {
                message('¡Ganaste ' + C.fmt(st.net) + '!', 'win');
            } else if (st.net < 0) {
                message('Perdiste ' + C.fmt(-st.net) + '.', 'lose');
            } else if (st.net === 0) {
                message('Empate: recuperás tu apuesta.');
            }
        } else {
            message('Poné tu apuesta y repartí');
        }

        if (st.phase === 'done' && st.net !== undefined) {
            S.bet = S.lastBet <= S.balance ? S.lastBet : 0;
        }
        renderChips();
        renderBetSpot();
        renderButtons();
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
                renderButtons();
                break;
            case 'expired':
            case 'closed':
                C.overlay('La mesa se cerró. Cerrá esta ventana y escribí /blackjack en el chat para volver a jugar.');
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
    var transport = new C.Transport(onMessage, onStatus);

    function action(type) {
        return function () {
            if (S.waiting) {
                return;
            }
            S.waiting = true;
            renderButtons();
            transport.send({ type: type });
        };
    }

    $('hit').onclick = action('hit');
    $('stand').onclick = action('stand');
    $('double').onclick = action('double');
    $('split').onclick = action('split');
    $('deal').onclick = function () {
        if (!canBet() || S.bet < S.min) {
            return;
        }
        S.lastBet = S.bet;
        S.waiting = true;
        S.shown = { dealer: 0, hands: [] };
        renderButtons();
        transport.send({ type: 'bet', amount: S.bet });
    };
    $('clearBet').onclick = function () {
        S.bet = 0;
        renderBetSpot();
        renderButtons();
    };
    $('rebet').onclick = function () {
        if (S.lastBet && S.lastBet <= S.balance) {
            S.bet = S.lastBet;
            renderBetSpot();
            renderButtons();
        }
    };

    renderBetSpot();
    renderButtons();
    transport.start();
})(window, document);
