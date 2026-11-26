import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/** Restore-dialog selection behavior for sections and their descendants. */
export default class RestoreSelection {
    /** @type {WeakMap<HTMLElement, number>} Invalidates pending validation when a form changes. */
    static #validationVersions = new WeakMap();

    /**
     * Binds cascading selection, descendant counts and placement feedback to a restore form.
     *
     * @param {HTMLElement} form
     */
    static bind(form) {
        form.addEventListener('change', (event) => {
            const checkbox = event.target;
            if (!checkbox.matches('input[type="checkbox"]')) {
                return;
            }
            RestoreSelection.#validationVersions.set(form, (RestoreSelection.#validationVersions.get(form) ?? 0) + 1);
            form.querySelectorAll('input[type="checkbox"]').forEach((input) => input.setCustomValidity(''));
            form.querySelector('[data-region="restore-selection-error"]')?.classList.add('d-none');

            if (checkbox.dataset.container === 'true') {
                checkbox.closest('.form-check')
                    ?.querySelectorAll('.item-children input[type="checkbox"][data-id]')
                    .forEach((child) => {
                        if (!child.disabled) {
                            child.checked = checkbox.checked;
                            child.indeterminate = false;
                        }
                    });
            }

            RestoreSelection.update(form).catch(Notification.exception);
        });

        RestoreSelection.update(form).catch(Notification.exception);
    }

    /**
     * Reports descendant selection separately from container inclusion and shows individual child placement.
     *
     * @param {HTMLElement} form
     * @return {Promise<void>}
     */
    static async update(form) {
        const containers = [...form.querySelectorAll('input[type="checkbox"][data-container="true"]')];
        containers.forEach((containerCheckbox) => {
            const container = containerCheckbox.closest('.form-check');
            const descendants = [...(container?.querySelectorAll(':scope > .item-children input[type="checkbox"][data-id]') ?? [])];
            // This checkbox restores the container itself; a mixed state would hide its actual inclusion state.
            containerCheckbox.indeterminate = false;
            const summary = container?.querySelector(':scope > [data-region="restore-descendant-selection"]');
            if (summary) {
                summary.classList.toggle('d-none', descendants.length === 0);
                const count = summary.querySelector('[data-region="restore-descendant-count"]');
                const selected = descendants.filter((checkbox) => checkbox.checked).length;
                const text = `${selected} / ${descendants.length}`;
                if (count.textContent !== text) {
                    count.textContent = text;
                }
            }
        });

        const root = form.querySelector('.import_form');
        const replaceDetails = root?.querySelector('input[data-action="replace_section_details"]')?.checked;
        const defaultDestination = replaceDetails
            ? (root?.dataset.restoreReplacementName || root?.dataset.restoreTargetName || '')
            : (root?.dataset.restoreTargetName ?? '');
        const hints = [...form.querySelectorAll('[data-region="restore-placement-hint"]')];

        await Promise.all(hints.map(async hint => {
            const item = hint.closest('.form-check')?.querySelector(
                ':scope > label > input[type="checkbox"][data-id]'
            );
            let parent = item?.closest('.form-check')?.parentElement?.closest('.form-check');
            let nearestContainer = null;
            let destination = defaultDestination;

            while (parent) {
                const parentCheckbox = parent.querySelector(':scope > label > input[type="checkbox"]');
                if (parentCheckbox?.dataset.container === 'true') {
                    if (!nearestContainer) {
                        nearestContainer = parentCheckbox;
                        if (parentCheckbox.checked) {
                            break;
                        }
                    } else if (parentCheckbox.checked) {
                        destination = parentCheckbox.dataset.title || destination;
                        break;
                    }
                }
                parent = parent.parentElement?.closest('.form-check');
            }

            if (!item?.checked || !nearestContainer || nearestContainer.checked || !destination) {
                hint.classList.add('d-none');
                hint.replaceChildren();
                delete hint.dataset.destination;
                return;
            }

            hint.classList.remove('d-none');
            hint.dataset.destination = destination;
            const placeholder = '@@RESTORE_DESTINATION@@';
            const message = await getString('restore_selected_item_in_section', 'block_sharing_cart', placeholder);

            if (hint.dataset.destination !== destination || hint.classList.contains('d-none')) {
                return;
            }

            const placeholderindex = message.indexOf(placeholder);
            if (placeholderindex < 0) {
                hint.textContent = message;
                return;
            }

            const destinationtext = document.createElement('strong');
            destinationtext.className = 'text-body fw-bold';
            destinationtext.textContent = destination;
            hint.replaceChildren(
                document.createTextNode(message.slice(0, placeholderindex)),
                destinationtext,
                document.createTextNode(message.slice(placeholderindex + placeholder.length))
            );
        }));
    }

    /**
     * Reads checked content IDs for the restore service.
     *
     * @param {HTMLElement} form
     * @return {{courseModuleIds: number[], sectionIds: number[], replaceSectionDetails: boolean}}
     */
    static read(form) {
        const idsOf = (type) => [...form.querySelectorAll('input[type="checkbox"]:checked')]
            .filter((checkbox) => checkbox.dataset.type === type)
            .map((checkbox) => Number(checkbox.dataset.id))
            .filter((id) => Number.isSafeInteger(id) && id > 0);

        const courseModuleIds = idsOf('coursemodule');
        const sectionIds = idsOf('section');
        // The backend treats an empty list as "all nested sections", so signal "none" explicitly.
        if (form.querySelector('input[type="checkbox"][data-type="section"]') && sectionIds.length === 0) {
            sectionIds.push(0);
        }

        return {
            courseModuleIds,
            sectionIds,
            replaceSectionDetails: Boolean(form.querySelector('input[data-action="replace_section_details"]:checked')),
        };
    }

    /**
     * Explain an empty selection even when the cart item has no selectable children.
     *
     * @param {HTMLElement} form restore modal
     * @return {Promise<void>}
     */
    static async reportEmpty(form) {
        const version = (RestoreSelection.#validationVersions.get(form) ?? 0) + 1;
        RestoreSelection.#validationVersions.set(form, version);
        const message = await getString('atleast_one_course_module_must_be_included', 'block_sharing_cart');
        if (!form.isConnected || RestoreSelection.#validationVersions.get(form) !== version) {
            return;
        }
        const {courseModuleIds, sectionIds} = RestoreSelection.read(form);
        if (courseModuleIds.length > 0 || sectionIds.some(id => id > 0)) {
            return;
        }
        const error = form.querySelector('[data-region="restore-selection-error"]');
        if (error) {
            error.textContent = message;
            error.classList.remove('d-none');
        }
        const input = form.querySelector('input[type="checkbox"]:not(:disabled)');
        input?.setCustomValidity(message);
        input?.reportValidity();
    }
}
