import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type', 'field'];

    connect() {
        this.updateFields();
    }

    updateFields() {
        for (const row of this.fieldTargets) {
            const active = row.dataset.sourceType === this.typeTarget.value;
            const input = row.querySelector('input');
            row.hidden = !active;
            input.disabled = !active;
            input.required = active;
        }
    }
}
