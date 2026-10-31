import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const url = new URL(window.location.href);
        // A partial or invalid explicit offset must also be preserved for server validation.
        if ([...url.searchParams.keys()].some((name) => /^tz_(sign|hours|minutes)(\[|$)/.test(name))) {
            return;
        }

        // getTimezoneOffset() returns minutes west of UTC, opposite to the form's sign.
        const offset = new Date().getTimezoneOffset();
        const minutes = Math.abs(offset);
        url.searchParams.set('tz_sign', offset > 0 ? '-' : '+');
        url.searchParams.set('tz_hours', String(Math.floor(minutes / 60)));
        url.searchParams.set('tz_minutes', String(minutes % 60));

        // Reload so the plotted points and today's defaults both use the browser's offset.
        window.location.replace(url.toString());
    }
}
