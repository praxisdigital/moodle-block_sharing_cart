import {getCurrentCourseEditor} from "core_courseformat/courseeditor";
import Ajax from "core/ajax";
import * as Toast from 'core/toast';
import LocalStorage from 'core/localstorage';
import {get_string as getString} from "core/str";

export default class QueueElement {
    /**
     * @type {BaseFactory}
     */
    #baseFactory;

    /**
     * @type {BlockElement}
     */
    #blockElement;

    /**
     * @type {HTMLElement}
     */
    #element;

    /** @type {HTMLElement} */
    #failures;

    /** @type {Set<string>} */
    #dismissedFailures = new Set();

    /**
     * @type {CourseEditor}
     */
    #reactive;

    /**
     * @type {Promise<void>|null}
     */
    #loadQueuePromise = null;

    /**
     * @type {Object|null}
     */
    #loadQueueToken = null;

    /** @type {number} */
    #queueRevision = 0;

    /**
     * @type {boolean}
     */
    #preventReload = false;

    /**
     * @type {boolean}
     */
    #showCopiesQueuedSegmentWhenEmpty = true;

    /**
     *
     * @param {BaseFactory} baseFactory
     * @param {BlockElement} blockElement
     * @param {HTMLElement} element
     * @param {boolean} showCopiesQueuedSegmentWhenEmpty
     */
    constructor(baseFactory, blockElement, element, showCopiesQueuedSegmentWhenEmpty) {
        this.#baseFactory = baseFactory;
        this.#blockElement = blockElement;
        this.#element = element;
        this.#failures = element.parentElement.querySelector('[data-region="restore-failures"]');
        this.#reactive = getCurrentCourseEditor();
        this.#showCopiesQueuedSegmentWhenEmpty = showCopiesQueuedSegmentWhenEmpty;

        this.tryReloadQueue(true);

        setInterval(() => {
            this.tryReloadQueue();
        }, 4000);
    }

    /**
     * @return {NodeListOf<Element>}
     */
    getQueueItems() {
        return this.#element.querySelectorAll('.queue-item');
    }

    /**
     * @param {boolean} ignoreQueueItemsCount
     */
    tryReloadQueue(ignoreQueueItemsCount = false) {
        if (this.#preventReload || (!ignoreQueueItemsCount && this.getQueueItems().length === 0)) {
            return;
        }

        if (this.#loadQueuePromise !== null) {
            this.#loadQueuePromise.then(() => {
                this.tryReloadQueue();
                return null;
            }).catch(() => {
                this.tryReloadQueue();
            });
            return;
        }

        this.#loadQueueToken = {};
        this.#loadQueuePromise = this.loadQueue(false, this.#loadQueueToken);
        this.#loadQueuePromise.then(() => {
            this.#loadQueuePromise = null;
            this.#loadQueueToken = null;
            return null;
        }).catch(() => {
            this.#loadQueueToken = null;
            this.#loadQueuePromise = null;
        });
    }

    /**
     * @param {Boolean} showSpinner
     * @param {Object} token
     * @return {Promise<void>}
     */
    async loadQueue(showSpinner = false, token = {}) {
        const revision = ++this.#queueRevision;
        token.abort = () => {
            if (this.#queueRevision === revision) {
                this.#queueRevision++;
            }
        };

        if (showSpinner && this.getQueueItems().length === 0) {
            this.#element.innerHTML = '<i class="fa fa-spinner"></i>';
        }
        const elements = await this.#baseFactory.moodle().template().createElementsFromFragment(
            'block_sharing_cart', 'item_queue', M.cfg.contextid, {}
        );
        if (this.#queueRevision !== revision) {
            return;
        }

        const fragments = elements.filter(element => element instanceof Element);
        const successes = fragments.find(element => element.matches('[data-region="restore-successes"]'));
        const successIds = new Set(successes?.dataset.taskIds.split(' ') ?? []);
        const queueItems = fragments.filter(element => element.classList.contains('queue-item'));
        const activeIds = new Set(queueItems.map(element => element.dataset.id));
        const completed = Array.from(this.getQueueItems()).filter(element =>
            !activeIds.has(element.dataset.id) && successIds.has(element.dataset.id));
        this.refreshSuccessfulRestores(completed);

        queueItems.forEach(element => this.bindRunNow(element));
        const empty = fragments.find(element => element.matches('[data-region="no-restores"]'));
        this.#element.replaceChildren(...queueItems, ...(empty ? [empty] : []));
        this.renderFailures(fragments.filter(element => element.matches('[data-region="restore-failure"]')));
    }

    /**
     * @param {Element[]} completed Confirmed restores that just finished.
     */
    refreshSuccessfulRestores(completed) {
        const refreshCourse = completed.some(element => element.dataset.refreshCourse === '1');
        const sectionIds = [...new Set(completed.map(element => element.dataset.toSectionId).filter(Boolean))];
        if (refreshCourse || sectionIds.length > 0) {
            const refresh = refreshCourse ?
                this.#reactive.dispatch('courseState') : this.#reactive.dispatch('sectionState', sectionIds);
            refresh.then(() => Toast.add(getString('you_may_need_to_reload_the_course_warning', 'block_sharing_cart'), {
                closeButton: true,
                autohide: true,
                type: 'warning'
            })).catch(() => {
                // Ignore course state refresh failures.
            });
        }
    }

    /**
     * @param {Element} element Active queue row.
     */
    bindRunNow(element) {
        const button = element.querySelector('[data-action="run-restore-now"]');
        button?.addEventListener('click', () => {
            button.disabled = true;
            this.#preventReload = true;
            this.#loadQueueToken?.abort();
            Ajax.call([{
                methodname: 'block_sharing_cart_run_task_now',
                args: {taskid: element.dataset.id}
            }]);
            setTimeout(() => {
                this.#preventReload = false;
                this.tryReloadQueue(true);
            }, 2000);
        }, {once: true});
    }

    /**
     * Keep notices in place during polling so dismiss buttons retain keyboard focus.
     * @param {Element[]} failures Notices returned by the server.
     */
    renderFailures(failures) {
        const storageKey = 'block_sharing_cart/dismissed_restores';
        const dismissed = new Set((LocalStorage.get(storageKey) || '').split(' '));
        const visible = failures.filter(element => !dismissed.has(element.dataset.id) &&
            !this.#dismissedFailures.has(element.dataset.id));
        const ids = new Set(visible.map(element => element.dataset.id));
        for (const element of Array.from(this.#failures.children)) {
            if (!ids.has(element.dataset.id)) {
                element.remove();
            }
        }
        const displayed = new Set(Array.from(this.#failures.children, element => element.dataset.id));
        for (const element of visible) {
            if (displayed.has(element.dataset.id)) {
                continue;
            }
            const button = element.querySelector('[data-action="dismiss-restore-error"]');
            button.addEventListener('click', () => {
                const id = element.dataset.id;
                this.#dismissedFailures.add(id);
                const previous = (LocalStorage.get(storageKey) || '').split(' ').filter(value => value && value !== id);
                LocalStorage.set(storageKey, [id, ...previous].slice(0, 100).join(' '));
                const restoreFocus = document.activeElement === button;
                element.remove();
                this.updateSegment();
                if (restoreFocus) {
                    const next = this.#failures.querySelector('button') ?? this.#element.closest('.block_sharing_cart')
                        ?.querySelector('.card-title');
                    if (next && !next.matches('button')) {
                        next.setAttribute('tabindex', '-1');
                    }
                    next?.focus();
                }
            });
            this.#failures.append(element);
        }
        this.updateSegment();
    }

    /** Update visibility when pending restores or failure notices change. */
    updateSegment() {
        const hasFailures = this.#failures.childElementCount > 0;
        const hasQueueItems = this.getQueueItems().length > 0;
        this.#element.hidden = hasFailures && !hasQueueItems;
        this.#element.parentElement.classList.toggle('d-none',
            !this.#showCopiesQueuedSegmentWhenEmpty && !hasQueueItems && !hasFailures);
    }
}
