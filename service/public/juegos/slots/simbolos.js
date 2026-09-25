/*
 * Claudia - arte de los símbolos de los slots (SVG propios, viewBox 100x100).
 * "{u}" se reemplaza por un id único en cada dibujo para no repetir ids de gradientes.
 */
(function (window) {
    'use strict';

    function suit(glyph, c1, c2) {
        return '<svg viewBox="0 0 100 100"><defs><radialGradient id="g{u}" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="' + c1 + '"/><stop offset="1" stop-color="' + c2 + '"/></radialGradient></defs>' +
            '<rect x="10" y="10" width="80" height="80" rx="18" fill="url(#g{u})" stroke="#fff6" stroke-width="3"/>' +
            '<text x="50" y="72" text-anchor="middle" font-size="62" font-family="Segoe UI Symbol, Arial Unicode MS, Arial, sans-serif" fill="#fff" stroke="#0005" stroke-width="2">' + glyph + '</text></svg>';
    }

    var S = {
        /* ---------------- Los 3 Chanchitos del Banco ---------------- */
        pica: suit('♠', '#6d7bd6', '#27306e'),
        trebol: suit('♣', '#4fc37b', '#17603a'),
        diamante: suit('♦', '#ffb347', '#b85c00'),
        corazon: suit('♥', '#ff6b81', '#9b1030'),
        billete:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="b{u}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9be27a"/><stop offset="1" stop-color="#2f7d32"/></linearGradient></defs>' +
            '<g transform="rotate(-12 50 50)"><rect x="8" y="26" width="84" height="48" rx="6" fill="#1f5e22"/><rect x="11" y="29" width="78" height="42" rx="4" fill="url(#b{u})" stroke="#e8ffd8" stroke-width="2"/>' +
            '<circle cx="50" cy="50" r="14" fill="#e8ffd8" stroke="#2f7d32" stroke-width="2"/><text x="50" y="58" text-anchor="middle" font-size="22" font-weight="bold" font-family="Georgia, serif" fill="#2f7d32">$</text>' +
            '<circle cx="22" cy="50" r="5" fill="#e8ffd8"/><circle cx="78" cy="50" r="5" fill="#e8ffd8"/></g></svg>',
        bolsa:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="s{u}" cx="40%" cy="40%" r="70%"><stop offset="0" stop-color="#e9d3a3"/><stop offset="1" stop-color="#8a6231"/></radialGradient></defs>' +
            '<path d="M35 30 Q50 18 65 30 L60 36 Q50 32 40 36 Z" fill="#8a6231"/><path d="M40 36 Q12 58 22 80 Q30 92 50 92 Q70 92 78 80 Q88 58 60 36 Z" fill="url(#s{u})" stroke="#5c3d17" stroke-width="2"/>' +
            '<rect x="37" y="33" width="26" height="6" rx="3" fill="#c9a24a"/><text x="50" y="76" text-anchor="middle" font-size="30" font-weight="bold" font-family="Georgia, serif" fill="#5c3d17">$</text></svg>',
        lingote:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="o{u}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff3a8"/><stop offset=".5" stop-color="#f2c230"/><stop offset="1" stop-color="#a87400"/></linearGradient></defs>' +
            '<path d="M14 80 L22 62 L48 62 L56 80 Z" fill="url(#o{u})" stroke="#7a5500" stroke-width="2"/><path d="M46 80 L54 62 L80 62 L88 80 Z" fill="url(#o{u})" stroke="#7a5500" stroke-width="2"/>' +
            '<path d="M30 60 L38 42 L64 42 L72 60 Z" fill="url(#o{u})" stroke="#7a5500" stroke-width="2"/><path d="M36 50 h28" stroke="#fff8" stroke-width="2"/></svg>',
        caja:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="c{u}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9aa3ad"/><stop offset="1" stop-color="#3b434b"/></linearGradient></defs>' +
            '<rect x="14" y="14" width="72" height="68" rx="6" fill="url(#c{u})" stroke="#23282d" stroke-width="3"/><rect x="20" y="20" width="60" height="56" rx="4" fill="none" stroke="#dfe4e8" stroke-width="2"/>' +
            '<circle cx="50" cy="48" r="16" fill="#23282d" stroke="#c9a24a" stroke-width="3"/><path d="M50 36 v24 M38 48 h24" stroke="#c9a24a" stroke-width="3"/>' +
            '<rect x="22" y="82" width="12" height="6" fill="#23282d"/><rect x="66" y="82" width="12" height="6" fill="#23282d"/></svg>',
        W:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="p{u}" cx="45%" cy="40%" r="65%"><stop offset="0" stop-color="#ffd1dc"/><stop offset="1" stop-color="#f06f95"/></radialGradient></defs>' +
            '<path d="M22 30 L18 10 L38 22 Z" fill="#e0587f"/><path d="M78 30 L82 10 L62 22 Z" fill="#e0587f"/><circle cx="50" cy="50" r="36" fill="url(#p{u})" stroke="#b83a60" stroke-width="3"/>' +
            '<circle cx="37" cy="42" r="5" fill="#2b1b20"/><circle cx="63" cy="42" r="5" fill="#2b1b20"/><circle cx="38.5" cy="40.5" r="1.6" fill="#fff"/><circle cx="64.5" cy="40.5" r="1.6" fill="#fff"/>' +
            '<ellipse cx="50" cy="60" rx="15" ry="11" fill="#f48aa8" stroke="#b83a60" stroke-width="2"/><ellipse cx="45" cy="60" rx="3" ry="4.5" fill="#8c2b48"/><ellipse cx="55" cy="60" rx="3" ry="4.5" fill="#8c2b48"/>' +
            '<rect x="20" y="78" width="60" height="16" rx="5" fill="#c9a24a" stroke="#7a5500" stroke-width="1.5"/><text x="50" y="91" text-anchor="middle" font-size="13" font-weight="bold" font-family="Arial Black, Arial, sans-serif" fill="#fff">WILD</text></svg>',
        S:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="h{u}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2704b"/><stop offset="1" stop-color="#9a3a1d"/></linearGradient></defs>' +
            '<path d="M12 46 L50 14 L88 46 Z" fill="#6b2d14" stroke="#3d170a" stroke-width="2"/><rect x="20" y="44" width="60" height="44" fill="url(#h{u})" stroke="#3d170a" stroke-width="2"/>' +
            '<path d="M20 55 h60 M20 66 h60 M20 77 h60 M35 44 v11 M60 44 v11 M28 55 v11 M50 55 v11 M72 55 v11 M38 66 v11 M62 66 v11" stroke="#f3b89d" stroke-width="1.5"/>' +
            '<rect x="42" y="64" width="16" height="24" rx="2" fill="#4a2a12"/><rect x="26" y="50" width="12" height="10" fill="#ffe59a" stroke="#3d170a"/><rect x="62" y="50" width="12" height="10" fill="#ffe59a" stroke="#3d170a"/>' +
            '<rect x="8" y="2" width="84" height="15" rx="5" fill="#2e8b57"/><text x="50" y="14" text-anchor="middle" font-size="11" font-weight="bold" font-family="Arial Black, Arial, sans-serif" fill="#fff">BONUS</text></svg>',
        L:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="l{u}" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#fff2a8"/><stop offset=".6" stop-color="#e8b020"/><stop offset="1" stop-color="#9a6a00"/></radialGradient></defs>' +
            '<circle cx="50" cy="50" r="42" fill="url(#l{u})" stroke="#7a5500" stroke-width="3"/><circle cx="50" cy="50" r="34" fill="none" stroke="#fff5" stroke-width="2"/>' +
            '<path d="M30 36 L36 20 L44 34 M70 36 L64 20 L56 34" fill="#7a5500"/><path d="M28 40 Q50 26 72 40 L66 58 Q50 70 34 58 Z" fill="#8a5d00" opacity=".55"/></svg>',
        K:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="k{u}" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#ffd6e2"/><stop offset="1" stop-color="#e45a86"/></radialGradient></defs>' +
            '<ellipse cx="50" cy="56" rx="38" ry="30" fill="url(#k{u})" stroke="#a8325a" stroke-width="3"/><rect x="40" y="26" width="20" height="5" rx="2" fill="#5a1830"/>' +
            '<path d="M30 34 L26 20 L40 28 Z" fill="#e45a86"/><ellipse cx="86" cy="56" rx="7" ry="9" fill="#f07ea3" stroke="#a8325a" stroke-width="2"/><circle cx="86" cy="53" r="1.6" fill="#5a1830"/><circle cx="86" cy="59" r="1.6" fill="#5a1830"/>' +
            '<circle cx="70" cy="46" r="3.5" fill="#2b1b20"/><rect x="26" y="80" width="10" height="10" rx="3" fill="#c9476f"/><rect x="62" y="80" width="10" height="10" rx="3" fill="#c9476f"/></svg>',

        /* ---------------- Dulce de Leche Bonanza ---------------- */
        chaja:
            '<svg viewBox="0 0 100 100"><path d="M14 44 L86 44 L80 86 L20 86 Z" fill="#fff4e0" stroke="#c89a5a" stroke-width="2"/>' +
            '<rect x="17" y="54" width="67" height="9" fill="#ffc94d"/><rect x="18" y="68" width="64" height="8" fill="#f5a524"/>' +
            '<path d="M12 44 Q20 24 32 36 Q40 18 52 34 Q62 16 70 34 Q82 22 88 44 Z" fill="#fff" stroke="#e8d8c0" stroke-width="2"/>' +
            '<circle cx="34" cy="38" r="5" fill="#ffd27a"/><circle cx="56" cy="36" r="5" fill="#ffd27a"/><circle cx="72" cy="39" r="4" fill="#ffd27a"/></svg>',
        alfajor:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="a{u}" cx="40%" cy="30%" r="70%"><stop offset="0" stop-color="#8a5a3a"/><stop offset="1" stop-color="#3d2112"/></radialGradient></defs>' +
            '<ellipse cx="50" cy="66" rx="38" ry="14" fill="#3d2112"/><rect x="12" y="50" width="76" height="16" fill="#e7c38c"/><ellipse cx="50" cy="50" rx="38" ry="4" fill="#f3e2c4"/>' +
            '<ellipse cx="50" cy="44" rx="38" ry="16" fill="url(#a{u})"/><path d="M26 40 Q50 30 74 40" stroke="#b07a55" stroke-width="3" fill="none" opacity=".6"/></svg>',
        bizcocho:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="z{u}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f7c86b"/><stop offset="1" stop-color="#b36b1f"/></linearGradient></defs>' +
            '<path d="M10 64 Q14 36 34 34 Q50 20 66 34 Q86 36 90 64 Q76 58 66 66 Q50 72 34 66 Q24 58 10 64 Z" fill="url(#z{u})" stroke="#8a4a10" stroke-width="2"/>' +
            '<path d="M34 36 Q40 52 34 66 M50 26 Q56 48 50 70 M66 36 Q60 52 66 66" stroke="#8a4a10" stroke-width="2" fill="none"/></svg>',
        garrapinada:
            '<svg viewBox="0 0 100 100"><path d="M24 30 L76 30 L58 92 L42 92 Z" fill="#f5f0e1" stroke="#b9a77a" stroke-width="2"/><path d="M28 40 h44 M31 52 h38" stroke="#d24b3a" stroke-width="3"/>' +
            '<circle cx="36" cy="26" r="8" fill="#b5651d"/><circle cx="50" cy="20" r="9" fill="#c97a2b"/><circle cx="64" cy="26" r="8" fill="#a85a17"/><circle cx="44" cy="30" r="6" fill="#d98c3c"/><circle cx="58" cy="31" r="6" fill="#b5651d"/>' +
            '<circle cx="47" cy="17" r="2" fill="#fff8"/><circle cx="62" cy="23" r="2" fill="#fff8"/></svg>',
        ciruela:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="i{u}" cx="35%" cy="35%" r="70%"><stop offset="0" stop-color="#c58bf2"/><stop offset="1" stop-color="#4b1570"/></radialGradient></defs>' +
            '<path d="M50 22 Q56 12 62 10" stroke="#5a3a1a" stroke-width="4" fill="none"/><path d="M52 20 Q70 8 78 20 Q66 26 52 20 Z" fill="#5fae3c"/>' +
            '<ellipse cx="50" cy="56" rx="32" ry="34" fill="url(#i{u})"/><path d="M50 26 Q42 56 50 88" stroke="#3a0f58" stroke-width="2" fill="none" opacity=".6"/></svg>',
        manzana:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="m{u}" cx="35%" cy="35%" r="70%"><stop offset="0" stop-color="#c9f27a"/><stop offset="1" stop-color="#3e8e1e"/></radialGradient></defs>' +
            '<path d="M50 28 Q52 16 58 12" stroke="#5a3a1a" stroke-width="4" fill="none"/><path d="M54 22 Q68 10 76 18 Q66 28 54 22 Z" fill="#2f7d20"/>' +
            '<path d="M50 30 Q28 18 18 42 Q12 70 34 86 Q44 92 50 86 Q56 92 66 86 Q88 70 82 42 Q72 18 50 30 Z" fill="url(#m{u})"/></svg>',
        sandia:
            '<svg viewBox="0 0 100 100"><path d="M8 34 Q50 110 92 34 Z" fill="#2f8a2f"/><path d="M13 36 Q50 100 87 36 Z" fill="#dff5c4"/><path d="M18 37 Q50 92 82 37 Z" fill="#ff4d5e"/>' +
            '<g fill="#2b1b1b"><ellipse cx="36" cy="48" rx="2.5" ry="4"/><ellipse cx="50" cy="56" rx="2.5" ry="4"/><ellipse cx="64" cy="48" rx="2.5" ry="4"/><ellipse cx="44" cy="68" rx="2.5" ry="4"/><ellipse cx="57" cy="68" rx="2.5" ry="4"/></g></svg>',
        uva:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="v{u}" cx="35%" cy="35%" r="70%"><stop offset="0" stop-color="#b8a6ff"/><stop offset="1" stop-color="#4028a0"/></radialGradient></defs>' +
            '<path d="M50 18 Q52 8 60 6" stroke="#5a3a1a" stroke-width="4" fill="none"/><path d="M52 16 Q66 6 72 16 Q62 22 52 16 Z" fill="#4f9e2f"/>' +
            '<g fill="url(#v{u})"><circle cx="36" cy="30" r="11"/><circle cx="58" cy="30" r="11"/><circle cx="26" cy="48" r="11"/><circle cx="47" cy="48" r="11"/><circle cx="68" cy="48" r="11"/><circle cx="36" cy="66" r="11"/><circle cx="58" cy="66" r="11"/><circle cx="47" cy="84" r="11"/></g></svg>',
        banana:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="n{u}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff38a"/><stop offset="1" stop-color="#e3b21a"/></linearGradient></defs>' +
            '<path d="M18 26 Q12 70 52 86 Q80 94 90 78 Q60 82 44 64 Q30 48 30 24 Z" fill="url(#n{u})" stroke="#a87c00" stroke-width="2"/><path d="M18 26 L24 14 L32 24 Z" fill="#6b4a14"/>' +
            '<path d="M86 80 L94 76 L92 84 Z" fill="#6b4a14"/></svg>',
        X:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="x{u}" cx="35%" cy="30%" r="70%"><stop offset="0" stop-color="#b3713c"/><stop offset=".7" stop-color="#5a2e12"/><stop offset="1" stop-color="#2d1406"/></radialGradient></defs>' +
            '<path d="M8 50 L20 38 L20 62 Z M92 50 L80 38 L80 62 Z" fill="#e8508a"/><circle cx="50" cy="50" r="32" fill="url(#x{u})" stroke="#e8508a" stroke-width="3"/>' +
            '<path d="M32 40 Q50 30 68 40" stroke="#d99a66" stroke-width="3" fill="none"/></svg>',

        /* ---------------- Mate Rush ---------------- */
        termo:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="t{u}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#6b7580"/><stop offset=".4" stop-color="#e8eef3"/><stop offset="1" stop-color="#4b545c"/></linearGradient></defs>' +
            '<rect x="34" y="6" width="32" height="14" rx="4" fill="#2d2d2d"/><path d="M62 10 Q78 12 76 24" stroke="#2d2d2d" stroke-width="4" fill="none"/>' +
            '<rect x="30" y="20" width="40" height="72" rx="10" fill="url(#t{u})" stroke="#3b434b" stroke-width="2"/><rect x="30" y="44" width="40" height="18" fill="#1f6fb5" opacity=".85"/></svg>',
        mate:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="e{u}" cx="35%" cy="35%" r="70%"><stop offset="0" stop-color="#d7a86e"/><stop offset="1" stop-color="#6b3f17"/></radialGradient></defs>' +
            '<path d="M58 4 L66 6 L52 44 L46 42 Z" fill="#d9dde0" stroke="#7a8288" stroke-width="1.5"/>' +
            '<path d="M24 42 Q20 90 50 92 Q80 90 76 42 Z" fill="url(#e{u})" stroke="#4a2a0e" stroke-width="2"/><ellipse cx="50" cy="42" rx="26" ry="8" fill="#3d7a1e" stroke="#c9a24a" stroke-width="3"/>' +
            '<path d="M28 60 Q50 68 72 60" stroke="#c9a24a" stroke-width="3" fill="none"/></svg>',
        yerba:
            '<svg viewBox="0 0 100 100"><rect x="24" y="10" width="52" height="82" rx="4" fill="#f5c400" stroke="#8a6d00" stroke-width="2"/><rect x="24" y="30" width="52" height="30" fill="#1e7d32"/>' +
            '<text x="50" y="50" text-anchor="middle" font-size="13" font-weight="bold" font-family="Arial Black, Arial, sans-serif" fill="#fff">YERBA</text>' +
            '<path d="M36 70 Q50 60 64 70 Q50 84 36 70 Z" fill="#1e7d32"/><path d="M24 14 h52" stroke="#8a6d00" stroke-width="2"/></svg>',
        bombilla:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="y{u}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#8f979d"/><stop offset=".5" stop-color="#f1f4f6"/><stop offset="1" stop-color="#6d757b"/></linearGradient></defs>' +
            '<g transform="rotate(25 50 50)"><rect x="46" y="4" width="8" height="66" rx="3" fill="url(#y{u})" stroke="#5c646a"/><path d="M42 6 h16" stroke="#c9a24a" stroke-width="5" stroke-linecap="round"/>' +
            '<ellipse cx="50" cy="80" rx="14" ry="16" fill="url(#y{u})" stroke="#5c646a" stroke-width="2"/><g stroke="#5c646a" stroke-width="1.5"><path d="M42 76 h16 M40 82 h20 M42 88 h16"/></g></g></svg>',
        pava:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="q{u}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e6ebef"/><stop offset="1" stop-color="#6b747c"/></linearGradient></defs>' +
            '<path d="M30 28 Q50 10 70 28" stroke="#2d2d2d" stroke-width="5" fill="none"/><path d="M22 44 Q22 30 50 30 Q78 30 78 44 L82 82 Q50 92 18 82 Z" fill="url(#q{u})" stroke="#3b434b" stroke-width="2"/>' +
            '<path d="M78 50 Q94 44 94 30" stroke="#8a9299" stroke-width="7" fill="none" stroke-linecap="round"/><rect x="44" y="24" width="12" height="7" rx="2" fill="#2d2d2d"/></svg>',
        tortafrita:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="f{u}" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#ffd98a"/><stop offset="1" stop-color="#c47a1f"/></radialGradient></defs>' +
            '<ellipse cx="50" cy="56" rx="40" ry="30" fill="url(#f{u})" stroke="#8a4a10" stroke-width="2"/><circle cx="50" cy="56" r="5" fill="#8a4a10" opacity=".6"/>' +
            '<g fill="#fff"><circle cx="30" cy="48" r="2"/><circle cx="40" cy="40" r="2"/><circle cx="62" cy="44" r="2"/><circle cx="70" cy="58" r="2"/><circle cx="36" cy="66" r="2"/><circle cx="56" cy="70" r="2"/></g></svg>',
        galleta:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="j{u}" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#f3dfb2"/><stop offset="1" stop-color="#b98a45"/></radialGradient></defs>' +
            '<circle cx="50" cy="52" r="38" fill="url(#j{u})" stroke="#7a5424" stroke-width="3"/><circle cx="50" cy="52" r="28" fill="none" stroke="#9c7038" stroke-width="2" stroke-dasharray="4 5"/>' +
            '<g fill="#7a5424"><circle cx="40" cy="44" r="2.5"/><circle cx="60" cy="44" r="2.5"/><circle cx="50" cy="56" r="2.5"/><circle cx="40" cy="64" r="2.5"/><circle cx="60" cy="64" r="2.5"/></g></svg>',
        sol:
            '<svg viewBox="0 0 100 100"><defs><radialGradient id="r{u}" cx="45%" cy="40%" r="60%"><stop offset="0" stop-color="#fff6b0"/><stop offset="1" stop-color="#f0a800"/></radialGradient></defs>' +
            '<g fill="#f5b800" stroke="#b37800" stroke-width="1"><path d="M50 2 L55 22 L45 22 Z"/><path d="M50 98 L55 78 L45 78 Z"/><path d="M2 50 L22 45 L22 55 Z"/><path d="M98 50 L78 45 L78 55 Z"/>' +
            '<path d="M16 16 L32 28 L28 32 Z"/><path d="M84 84 L68 72 L72 68 Z"/><path d="M84 16 L72 32 L68 28 Z"/><path d="M16 84 L28 68 L32 72 Z"/></g>' +
            '<circle cx="50" cy="50" r="26" fill="url(#r{u})" stroke="#b37800" stroke-width="2"/><circle cx="42" cy="46" r="3" fill="#8a5a00"/><circle cx="58" cy="46" r="3" fill="#8a5a00"/>' +
            '<path d="M40 58 Q50 66 60 58" stroke="#8a5a00" stroke-width="3" fill="none"/></svg>',
        R:
            '<svg viewBox="0 0 100 100"><defs><linearGradient id="w{u}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fffbe0"/><stop offset=".5" stop-color="#ffd23f"/><stop offset="1" stop-color="#ff8a00"/></linearGradient></defs>' +
            '<circle cx="50" cy="50" r="44" fill="#3a1d6e" stroke="#9f6bff" stroke-width="3"/><path d="M58 8 L26 56 L46 56 L38 92 L74 40 L54 40 Z" fill="url(#w{u})" stroke="#b35a00" stroke-width="2"/></svg>'
    };

    // Chupetín (scatter de Dulce de Leche Bonanza).
    S['dulce.S'] =
        '<svg viewBox="0 0 100 100"><defs><radialGradient id="d{u}" cx="40%" cy="35%" r="65%"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#ffc1dc"/></radialGradient></defs>' +
        '<rect x="46" y="58" width="8" height="40" rx="3" fill="#f3f3f3" stroke="#bbb"/><circle cx="50" cy="40" r="34" fill="url(#d{u})" stroke="#e8508a" stroke-width="3"/>' +
        '<path d="M50 40 m0 -4 a4 4 0 1 1 -4 4 a8 8 0 1 1 8 8 a12 12 0 1 1 -12 -12 a16 16 0 1 1 16 16 a20 20 0 1 1 -20 -20 a24 24 0 1 1 24 24" fill="none" stroke="#e8508a" stroke-width="5" stroke-linecap="round"/>' +
        '<rect x="14" y="70" width="72" height="16" rx="6" fill="#7b2fa0"/><text x="50" y="82.5" text-anchor="middle" font-size="12" font-weight="bold" font-family="Arial Black, Arial, sans-serif" fill="#fff">BONUS</text></svg>';
    // Sol de Mayo (bonus de Mate Rush).
    S['materush.S'] = S.sol;

    var uid = 0;

    /** SVG de un símbolo para un juego (usa "juego.codigo" si existe, si no "codigo"). */
    function svg(game, code) {
        var key = game + '.' + code;
        var src = S[key] || S[code] || '';
        uid++;
        return src.replace(/\{u\}/g, 'u' + uid);
    }

    /*
     * Versión bitmap de los símbolos. El navegador del MOTD del juego no tiene GPU: mover decenas de
     * SVG con degradados en cada cuadro lo traba. Cada símbolo se rasteriza una vez a PNG al tamaño
     * real en pantalla y las celdas usan <img>. Mientras tanto (o si falla) se usa el SVG como imagen.
     */
    var urls = {};
    var px = 0;

    function key(game, code) {
        return S[game + '.' + code] ? game + '.' + code : code;
    }

    function svgUrl(k) {
        var src = (S[k] || '').replace(/\{u\}/g, 'u0')
            .replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" ');
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(src);
    }

    /** Rasteriza todos los símbolos a "size" píxeles de lado (llamar de nuevo si cambia la escala). */
    function warm(size) {
        size = Math.max(24, Math.ceil(size));
        if (size === px) {
            return;
        }
        px = size;
        for (var k in S) {
            if (S.hasOwnProperty(k)) {
                raster(k, size);
            }
        }
    }

    function raster(k, size) {
        var im = new Image();
        im.onload = function () {
            if (size !== px) {
                return;
            }
            try {
                var c = document.createElement('canvas');
                c.width = size;
                c.height = size;
                c.getContext('2d').drawImage(im, 0, 0, size, size);
                urls[k] = c.toDataURL('image/png');
            } catch (e) {
                urls[k] = im.src;
            }
        };
        im.src = svgUrl(k);
    }

    /** HTML de un <img> con el símbolo (bitmap si ya está listo). */
    function img(game, code, cls) {
        var k = key(game, code);
        if (!S[k]) {
            return '';
        }
        return '<img class="sym' + (cls ? ' ' + cls : '') + '" src="' + (urls[k] || svgUrl(k)) + '" alt="" draggable="false">';
    }

    window.SlotSymbols = { svg: svg, img: img, warm: warm, raw: S };
})(window);
