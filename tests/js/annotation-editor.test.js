'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const editor = require('../../resources/js/annotation-editor.js');

test('clamp keeps coordinates inside the canvas', () => {
    assert.equal(editor.clamp(-4, 0, 100), 0);
    assert.equal(editor.clamp(54, 0, 100), 54);
    assert.equal(editor.clamp(140, 0, 100), 100);
});

test('canvasPoint maps scaled pointer coordinates into backing pixels', () => {
    const canvas = {
        width: 1000,
        height: 500,
        getBoundingClientRect: () => ({ left: 20, top: 10, width: 500, height: 250 }),
    };
    assert.deepEqual(editor.canvasPoint({ clientX: 270, clientY: 135 }, canvas), { x: 500, y: 250 });
    assert.deepEqual(editor.canvasPoint({ clientX: -20, clientY: 500 }, canvas), { x: 0, y: 500 });
});

test('arrowHead returns two finite points behind the arrow endpoint', () => {
    const points = editor.arrowHead({ x: 0, y: 0 }, { x: 100, y: 0 }, 20);
    assert.equal(points.length, 2);
    points.flatMap(({ x, y }) => [x, y]).forEach((value) => assert.equal(Number.isFinite(value), true));
    assert.ok(points[0].x < 100);
    assert.ok(points[1].x < 100);
});

test('history supports add, undo, redo, and invalidates redo after a new mark', () => {
    const history = editor.createHistory();
    const first = { type: 'pen' };
    const second = { type: 'arrow' };
    history.add(first);
    history.add(second);
    assert.deepEqual(history.actions, [first, second]);
    history.undo();
    assert.deepEqual(history.actions, [first]);
    assert.equal(history.canRedo, true);
    history.redo();
    assert.deepEqual(history.actions, [first, second]);
    history.undo();
    history.add({ type: 'text' });
    assert.equal(history.canRedo, false);
});

test('clear is undoable as a single history operation', () => {
    const history = editor.createHistory();
    history.add({ type: 'pen' });
    history.add({ type: 'rectangle' });
    history.clear();
    assert.deepEqual(history.actions, []);
    assert.equal(history.canUndo, true);
    history.undo();
    assert.equal(history.actions.length, 2);
    history.redo();
    assert.deepEqual(history.actions, []);
});

test('drawAction renders pen, rectangle, arrow, text, and batch actions', () => {
    const calls = [];
    const context = new Proxy({
        save: () => calls.push('save'), restore: () => calls.push('restore'),
        beginPath: () => calls.push('beginPath'), moveTo: () => calls.push('moveTo'),
        lineTo: () => calls.push('lineTo'), stroke: () => calls.push('stroke'),
        strokeRect: () => calls.push('strokeRect'), strokeText: () => calls.push('strokeText'),
        fillText: () => calls.push('fillText'),
    }, { set(target, property, value) { target[property] = value; return true; } });

    editor.drawAction(context, { type: 'pen', points: [{ x: 0, y: 0 }, { x: 2, y: 2 }], color: '#f00', width: 2 });
    editor.drawAction(context, { type: 'rectangle', start: { x: 0, y: 0 }, end: { x: 2, y: 2 }, color: '#f00', width: 2 });
    editor.drawAction(context, { type: 'arrow', start: { x: 0, y: 0 }, end: { x: 2, y: 2 }, color: '#f00', width: 2 });
    editor.drawAction(context, { type: 'text', start: { x: 0, y: 0 }, text: 'bug', color: '#f00', width: 2 });
    editor.drawAction(context, { type: 'batch', actions: [{ type: 'rectangle', start: { x: 0, y: 0 }, end: { x: 1, y: 1 }, color: '#f00', width: 1 }] });

    ['stroke', 'strokeRect', 'strokeText', 'fillText'].forEach((name) => assert.ok(calls.includes(name)));
});
