import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

// The import map resolves Stimulus in the browser. Only its base class is needed to exercise connect().
const source = (await readFile(new URL('../../assets/controllers/map_timezone_controller.js', import.meta.url), 'utf8'))
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}');
const { default: MapTimezoneController } = await import(`data:text/javascript,${encodeURIComponent(source)}`);

for (const [name, offset, sign, hours, minutes] of [
    ['Japan', -540, '+', '9', '0'],
    ['Nepal', -345, '+', '5', '45'],
    ['negative fractional offset', 210, '-', '3', '30'],
    ['negative zero hours', 30, '-', '0', '30'],
    ['UTC', 0, '+', '0', '0'],
]) {
    test(`initial visit uses browser offset: ${name}`, (t) => {
        t.mock.method(Date.prototype, 'getTimezoneOffset', () => offset);
        let redirected;
        const originalWindow = globalThis.window;
        globalThis.window = { location: {
            href: 'https://example.com/points/map?start=2026-11-01T00%3A00#map',
            replace: (url) => { redirected = new URL(url); },
        } };
        t.after(() => { globalThis.window = originalWindow; });

        new MapTimezoneController().connect();

        assert.equal(redirected.searchParams.get('tz_sign'), sign);
        assert.equal(redirected.searchParams.get('tz_hours'), hours);
        assert.equal(redirected.searchParams.get('tz_minutes'), minutes);
        assert.equal(redirected.searchParams.get('start'), '2026-11-01T00:00');
        assert.equal(redirected.searchParams.has('end'), false);
        assert.equal(redirected.hash, '#map');

        // A second connection must preserve the resolved offset and avoid a redirect loop.
        window.location.href = redirected.toString();
        redirected = undefined;
        new MapTimezoneController().connect();
        assert.equal(redirected, undefined);
    });
}

for (const query of ['tz_hours=0', 'tz_sign=-', 'tz_minutes=30', 'tz_hours%5B%5D=bad']) {
    test(`preserves explicit timezone parameters: ${query}`, (t) => {
        const originalWindow = globalThis.window;
        globalThis.window = { location: {
            href: `https://example.com/points/map?${query}`,
            replace: () => assert.fail('An explicit timezone must not be replaced'),
        } };
        t.after(() => { globalThis.window = originalWindow; });

        new MapTimezoneController().connect();
    });
}
