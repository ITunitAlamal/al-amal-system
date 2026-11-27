// Icon paths shared by the sidebar and the «القائمة الرئيسية» editor (24×24, stroked).
// The menu icons must match NavigationBuilder::ICONS on the server.
export const menuIconPaths = {
    layout: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    folder: 'M3 6h7l2 2h9v12H3z',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-4 M16 3a4 4 0 0 1 0 8',
    building: 'M4 21V3h16v18 M2 21h20 M8 7h1 M15 7h1 M8 11h1 M15 11h1 M10 21v-5h4v5',
    coins: 'M3 6c0-4 12-4 12 0s-12 4-12 0 M3 6v5c0 4 12 4 12 0V6 M3 11v5c0 4 12 4 12 0 M18 8c4 0 4 6 0 6 M18 14c4 0 4 6 0 6',
    'clipboard-list': 'M9 3h6v4H9z M9 5H5v16h14V5h-4 M8 12h8 M8 16h8',
    wrench: 'M21 3l-5 5-3-3 5-5a7 7 0 0 0-8 9L2 17a3 3 0 0 0 4 4l8-8a7 7 0 0 0 7-10',
    save: 'M3 3h15l3 3v15H3z M7 3v6h10V3 M7 21v-8h10v8',
    calendar: 'M3 5h18v16H3z M7 2v6 M17 2v6 M3 11h18',
    handshake: 'M2 8l4-4 5 1 3-1 8 5-4 9-6 3-8-6z M7 9l4-4 M11 12l6 5',
    'shopping-cart': 'M2 3h3l3 13h11l3-9H6 M10 21h1 M18 21h1',
    inbox: 'M3 13h5l2 3h4l2-3h5 M5 5h14l2 8v6H3v-6z',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M19 12l2-1-1-3-2 .5-1.5-1.5.5-2-3-1-1 2h-2l-1-2-3 1 .5 2L6 7.5 4 7 3 10l2 1v2l-2 1 1 3 2-.5 1.5 1.5-.5 2 3 1 1-2h2l1 2 3-1-.5-2 1.5-1.5 2 .5 1-3-2-1z',
    key: 'M15 7a4 4 0 1 1-3.5 6L5 19.5V22H2v-3l6.5-6.5A4 4 0 0 1 15 7 M16 8h.01',
    'git-branch': 'M6 3v12 M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M18 9a9 9 0 0 1-9 9',
    list: 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
    chart: 'M3 3v18h18 M7 15l4-4 3 3 5-6',
    mail: 'M3 5h18v14H3z M3 7l9 6 9-6',
    wallet: 'M3 7h16v12H3z M3 7l3-4h11l2 4 M16 13h3',
    heart: 'M12 21s-8-5.5-8-11a5 5 0 0 1 8-4 5 5 0 0 1 8 4c0 5.5-8 11-8 11z',
    'file-text': 'M6 2h9l5 5v15H6z M14 2v6h6 M9 13h8 M9 17h8 M9 9h2',
    boxes: 'M3 8l4-2 4 2v5l-4 2-4-2z M13 8l4-2 4 2v5l-4 2-4-2z M8 15l4-2 4 2v5l-4 2-4-2z',
    'package-check': 'M21 8l-9-5-9 5v8l9 5 4-2 M3 8l9 5 9-5 M12 13v8 M16 18l2 2 4-4',
    receipt: 'M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2z M9 7h6 M9 11h6 M9 15h4',
    'file-spreadsheet': 'M6 2h9l5 5v15H6z M14 2v6h6 M8 12h8v7H8z M8 15.5h8 M12 12v7',
    'file-down': 'M6 2h9l5 5v15H6z M14 2v6h6 M12 11v7 M9 15l3 3 3-3',
    printer: 'M6 9V2h12v7 M6 18H3v-8h18v8h-3 M6 14h12v8H6z',
    banknote: 'M2 6h20v12H2z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M6 12h.01 M18 12h.01',
    'hand-coins': 'M11 15h2a2 2 0 0 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17 M7 21l1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.8-2.8L14.4 14 M2 16l6 6 M16 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
    'book-open': 'M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2z M22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16 M21 21l-4.3-4.3',
    refresh: 'M21 12a9 9 0 1 1-3-6.7L21 8 M21 3v5h-5',
    plus: 'M12 5v14 M5 12h14',
    'chevron-down': 'M6 9l6 6 6-6',
    menu: 'M4 6h16 M4 12h16 M4 18h16',
    close: 'M6 6l12 12 M18 6L6 18',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
    'panel-collapse': 'M3 4h18v16H3z M9 4v16 M15 10l-2 2 2 2',
    // Workflow action buttons (always beside the action's text, never alone).
    check: 'M5 12.5l4.5 4.5L19 7', // approve / confirm
    undo: 'M15 14l5-5-5-5 M20 9H10a6 6 0 0 0 0 12h4', // send back (the arrow points to the start side in RTL)
    'circle-check': 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18 M8 12.5l3 3 5-6', // close a request
    'user-plus': 'M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M8.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M19 8v6 M16 11h6', // assign to a person
    send: 'M21 3L3 10.5l7 3 3 7.5z M10 13.5L21 3', // forward to the next stage
    paperclip: 'M20 11l-8.5 8.5a5 5 0 0 1-7-7L13 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L14 7', // attachments
};
