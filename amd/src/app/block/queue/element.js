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

    /** @type {Set<string>} Terminal results already processed on this page. */
    #processedRestores = new Set();

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

    /** @type {boolean} A restore was accepted but no queue fetch has succeeded yet. */
    #awaitingQueue = false;

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

    /** Retry via normal polling if the first fetch after accepting a restore fails. */
    async restoreAccepted() {
        this.#awaitingQueue = true;
        try {
            await this.loadQueue(true);
        } catch {
            // The interval will retry even if there is no queue row yet.
        }
    }

    /**
     * @param {boolean} ignoreQueueItemsCount
     */
    tryReloadQueue(ignoreQueueItemsCount = false) {
        if (this.#preventReload || (!ignoreQueueItemsCount && !this.#awaitingQueue && this.getQueueItems().length === 0)) {
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
        this.#awaitingQueue = false;

        const fragments = elements.filter(element => element instanceof Element);
        const queueItems = fragments.filter(element => element.classList.contains('queue-item'));
        const failures = fragments.filter(element => element.matches('[data-region="restore-failure"]'));
        const successes = fragments.filter(element => element.matches('[data-region="restore-success"]'));
        this.processRestoreResults(queueItems, successes, failures);

        queueItems.forEach(element => this.bindRunNow(element));
        const empty = fragments.find(element => element.matches('[data-region="no-restores"]'));
        this.#element.replaceChildren(...queueItems, ...(empty ? [empty] : []));
        this.renderFailures(failures);
    }

    /**
     * Refresh affected destinations once, even when a task completed before its first poll.
     * @param {Element[]} queueItems Current task rows.
     * @param {Element[]} successes Confirmed successes with destination metadata.
     * @param {Element[]} failures Confirmed failures.
     */
    processRestoreResults(queueItems, successes, failures) {
        const observed = new Map(Array.from(this.getQueueItems(), element => [element.dataset.id, element]));
        const activeIds = new Set(queueItems.map(element => element.dataset.id));
        const failedIds = new Set(failures.map(element => element.dataset.id));
        const pageTime = Number(this.#element.dataset.pageTime);
        const completed = [];
        let notify = false;

        const warnings = failures.filter(element => element.dataset.refreshDestination === '1');
        for (const result of [...successes, ...warnings]) {
            const {id, time, toSectionId, refreshCourse} = result.dataset;
            if (this.#processedRestores.has(id) || activeIds.has(id) ||
                    (!observed.has(id) && Number(time) < pageTime)) {
                continue;
            }
            this.#processedRestores.add(id);
            completed.push(toSectionId || refreshCourse ? result : observed.get(id) ?? result);
            notify ||= !failedIds.has(id);
        }

        for (const [id, row] of observed) {
            if (!activeIds.has(id) && !failedIds.has(id) && !this.#processedRestores.has(id)) {
                // A missing outcome is not evidence of success (the cache may have been cleared).
                this.#processedRestores.add(id);
                completed.push(row);
            }
        }

        const refreshCourse = completed.some(element => element.dataset.refreshCourse === '1');
        const sectionIds = [...new Set(completed.map(element => element.dataset.toSectionId).filter(id => Number(id) > 0))];
        if (refreshCourse || sectionIds.length > 0) {
            const refresh = refreshCourse ?
                this.#reactive.dispatch('courseState') : this.#reactive.dispatch('sectionState', sectionIds);
            refresh.then(() => {
                if (notify) {
                    return Toast.add(getString('you_may_need_to_reload_the_course_warning', 'block_sharing_cart'), {
                        closeButton: true,
                        autohide: true,
                        type: 'warning'
                    });
                }
                return null;
            }).catch(() => Toast.add(getString('restore_refresh_failed', 'block_sharing_cart'), {
                closeButton: true,
                autohide: false,
                type: 'warning'
            }));
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
