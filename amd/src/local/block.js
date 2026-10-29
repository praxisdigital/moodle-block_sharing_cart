import {BaseComponent} from 'core/reactive';
import {get_string as getString} from 'core/str';
import {getCurrentCourseEditor} from "core_courseformat/courseeditor";
import BaseFactory from "../app/factory";

export default class Block extends BaseComponent {
    /**
     * @type {CourseElement|null}
     */
    course;

    /**
     * @type {BlockElement}
     */
    block;

    /**
     * @type {QueueElement}
     */
    queue;

    /**
     * Observes the course content DOM so the sharing-cart icon can be
     * re-added whenever core_courseformat replaces a course module's DOM node.
     * @type {MutationObserver}
     */
    #courseContentObserver;

    /**
     * Retries basket-icon refreshes for course modules that appear in the DOM
     * before their reactive state has been created.
     * @type {Map<String, number>}
     */
    #pendingCourseModuleRefreshes = new Map();

    /**
     * Retries basket-icon refreshes for sections that appear before their
     * reactive state has been created.
     * @type {Map<String, number>}
     */
    #pendingCourseSectionRefreshes = new Map();

    /**
     * Serialises simultaneous basket refreshes for the same course module.
     * @type {Map<String, Promise>}
     */
    #courseModuleRefreshes = new Map();

    /**
     * Records course modules that need one more refresh after an active refresh.
     * @type {Set<String>}
     */
    #queuedCourseModuleRefreshes = new Set();

    /**
     * Section basket refreshes deferred until a course-item drag ends.
     * @type {Set<String>}
     */
    #deferredCourseSectionRefreshes = new Set();

    /**
     * Course-module basket refreshes deferred until a course-item drag ends.
     * @type {Set<String>}
     */
    #deferredCourseModuleRefreshes = new Set();

    /** @type {Object|null} */
    #draggedSectionLocation = null;

    /** @type {Object|null} */
    #draggedCourseModuleLocation = null;

    /**
     * Constructor hook.
     * @param {Object} descriptor
     */
    create(descriptor) {
        // Optional component name for debugging.
        this.name = 'sharing_cart_block';
        // Default query selectors.
        this.selectors = {
            COPY_SECTION_CONTAINER: '#copy_section_container',
        };

        this.canBackupUserdata = descriptor.canBackupUserdata ?? false;
        this.canAnonymizeUserdata = descriptor.canAnonymizeUserdata ?? false;
        this.canBackup = descriptor.canBackup ?? false;
        this.showSharingCartBasket = descriptor.showSharingCartBasket ?? false;
        this.showCopiesQueuedSegmentWhenEmpty = descriptor.showCopiesQueuedSegmentWhenEmpty ?? true;
    }

    /**
     * Static method to create a component instance form the mustache template.
     *
     * @param {String} target
     * @param {Boolean} canBackupUserdata
     * @param {Boolean} canAnonymizeUserdata
     * @param {Boolean} canBackup
     * @param {Boolean} showSharingCartBasket
     * @param {Boolean} showCopiesQueuedSegmentWhenEmpty
     */
    static init(
        target,
        canBackupUserdata,
        canAnonymizeUserdata,
        canBackup,
        showSharingCartBasket,
        showCopiesQueuedSegmentWhenEmpty
    ) {
        return new this({
            element: document.getElementById(target),
            reactive: getCurrentCourseEditor(),
            canBackupUserdata,
            canAnonymizeUserdata,
            canBackup,
            showSharingCartBasket,
            showCopiesQueuedSegmentWhenEmpty
        });
    }

    /**
     * Initial state ready method.
     */
    stateReady() {
        this.baseFactory = BaseFactory.make();
        const {course, block, queue} = this.baseFactory.block().eventHandler().onLoad(
            this.canBackupUserdata,
            this.canAnonymizeUserdata,
            this.canBackup,
            this.showSharingCartBasket,
            this.showCopiesQueuedSegmentWhenEmpty
        );

        this.course = course;
        this.block = block;
        this.queue = queue;

        const courseContents = document.querySelectorAll('.course-content');
        if (courseContents.length > 0) {
            courseContents.forEach(courseContent => {
                const sectionElements = courseContent.querySelectorAll('[data-for="section"]');
                sectionElements.forEach(sectionElement => {
                    this._refreshSectionWhenReady(sectionElement.dataset.id);
                });

                const courseModuleElements = courseContent.querySelectorAll('[data-for="cmitem"]');
                courseModuleElements.forEach(courseModuleElement => {
                    this._refreshCourseModuleWhenReady(courseModuleElement.dataset.id);
                });
            });
        }

        const showCopySectionInBlockSegment = this.getElement(this.selectors.COPY_SECTION_CONTAINER);
        if (showCopySectionInBlockSegment && course) {
            this._refreshCopySectionOptions();

            const select = showCopySectionInBlockSegment.querySelector('select');
            const copySectionButton = showCopySectionInBlockSegment.querySelector('button');
            copySectionButton.addEventListener('click', async() => {
                await this.block.addSectionBackupToSharingCart(select.value);
            });
        } else {
            showCopySectionInBlockSegment?.classList.add('d-none');
        }

        this._initCourseContentObserver();
    }

    /**
     * Sets up a MutationObserver watching the course content area(s) so the
     * sharing-cart icon can be re-added whenever core_courseformat replaces
     * a course module's DOM node
     */
    _initCourseContentObserver() {
        if (!this.showSharingCartBasket || !this.canBackup) {
            return;
        }

        const courseContents = document.querySelectorAll('.course-content');
        if (courseContents.length === 0) {
            return;
        }

        if (this.#courseContentObserver) {
            this.#courseContentObserver.disconnect();
        }

        this.#courseContentObserver = new MutationObserver((mutations) => {
            const affectedCourseModuleIds = new Set();
            const affectedSectionIds = new Set();

            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof HTMLElement)) {
                        return;
                    }

                    if (node.matches('[data-for="cmitem"]')) {
                        affectedCourseModuleIds.add(node.dataset.id);
                    }
                    if (node.matches('[data-for="section"]')) {
                        affectedSectionIds.add(node.dataset.id);
                    }

                    node.querySelectorAll('[data-for="cmitem"]').forEach((cmitem) => {
                        affectedCourseModuleIds.add(cmitem.dataset.id);
                    });
                    node.querySelectorAll('[data-for="section"]').forEach((section) => {
                        affectedSectionIds.add(section.dataset.id);
                    });
                });
            });

            affectedCourseModuleIds.forEach((courseModuleId) => {
                this._refreshCourseModuleWhenReady(courseModuleId);
            });
            affectedSectionIds.forEach((sectionId) => {
                this._refreshSectionWhenReady(sectionId);
            });
        });

        courseContents.forEach((courseContent) => {
            this.#courseContentObserver.observe(courseContent, {
                childList: true,
                subtree: true,
            });
        });
    }

    /**
     * Refresh a course module after its reactive state is available. Course
     * format mutations can insert the rendered node just before state creation.
     * @param {String} courseModuleId
     * @param {Number} attempt
     */
    _refreshCourseModuleWhenReady(courseModuleId, attempt = 0) {
        const courseModule = this.reactive.state.cm.get(courseModuleId);
        if (courseModule) {
            const timeout = this.#pendingCourseModuleRefreshes.get(courseModuleId);
            if (timeout) {
                clearTimeout(timeout);
                this.#pendingCourseModuleRefreshes.delete(courseModuleId);
            }
            this._refreshCourseModule({element: courseModule});
            return;
        }

        // The cm:created watcher normally catches this. Retry briefly as well
        // because some course-format mutations render the node first.
        const maxAttempts = 20;
        if (attempt >= maxAttempts || this.#pendingCourseModuleRefreshes.has(courseModuleId)) {
            return;
        }

        const timeout = setTimeout(() => {
            this.#pendingCourseModuleRefreshes.delete(courseModuleId);
            this._refreshCourseModuleWhenReady(courseModuleId, attempt + 1);
        }, 50);
        this.#pendingCourseModuleRefreshes.set(courseModuleId, timeout);
    }

    /**
     * Refresh a section after its reactive state is available.
     * @param {String} sectionId
     * @param {Number} attempt
     */
    _refreshSectionWhenReady(sectionId, attempt = 0) {
        const section = this.reactive.state.section.get(sectionId);
        if (section) {
            const timeout = this.#pendingCourseSectionRefreshes.get(sectionId);
            if (timeout) {
                clearTimeout(timeout);
                this.#pendingCourseSectionRefreshes.delete(sectionId);
            }
            this._refreshSection({element: section});
            return;
        }

        const maxAttempts = 20;
        if (attempt >= maxAttempts || this.#pendingCourseSectionRefreshes.has(sectionId)) {
            return;
        }

        const timeout = setTimeout(() => {
            this.#pendingCourseSectionRefreshes.delete(sectionId);
            this._refreshSectionWhenReady(sectionId, attempt + 1);
        }, 50);
        this.#pendingCourseSectionRefreshes.set(sectionId, timeout);
    }

    /**
     * Component watchers.
     *
     * @returns {Array} of watchers
     */
    getWatchers() {
        return [
            {watch: `section:created`, handler: this._refreshSection},
            {watch: `section:updated`, handler: this._refreshSection},
            {watch: `section.dragging:created`, handler: this._onDraggingSection},
            {watch: `section.dragging:updated`, handler: this._onDraggingSection},
            {watch: `cm.dragging:created`, handler: this._onDraggingCourseModule},
            {watch: `cm.dragging:updated`, handler: this._onDraggingCourseModule},
            {watch: `cm:created`, handler: this._refreshCourseModule},
            {watch: `cm:updated`, handler: this._refreshCourseModule},
        ];
    }

    async getBackupToSharingCartButton() {
        if (!this._sharingCartButton) {
            this._sharingCartButton = await this.baseFactory.moodle().template().createElementFromTemplate(
                'block_sharing_cart/block/course/add_to_sharing_cart_button',
                {}
            );
        }

        return this._sharingCartButton.cloneNode(true);
    }

    async _refreshCopySectionOptions() {
        const showCopySectionInBlockSegment = this.getElement(this.selectors.COPY_SECTION_CONTAINER);
        if (!showCopySectionInBlockSegment) {
            return;
        }

        const select = showCopySectionInBlockSegment.querySelector('select');
        const selectedValue = select.value;

        const noCourseModulesInSections = await getString('no_course_modules_in_section', 'block_sharing_cart');

        const div = document.createElement('div');

        const option = document.createElement('option');
        option.disabled = true;
        option.text = await getString('choosedots', 'core');
        div.appendChild(option);

        this.reactive.state.section.forEach((section) => {
            const option = document.createElement('option');

            const sectionIsEmpty = section.cmlist.length === 0;
            if (sectionIsEmpty) {
                option.disabled = true;
                option.title = noCourseModulesInSections;
            }

            option.value = section.id;
            option.text = section.title;
            option.selected = Number.parseInt(section.id) === Number.parseInt(selectedValue);

            div.appendChild(option);
        });

        select.innerHTML = div.innerHTML;
    }

    /**
     * Refresh the section.
     * @param {Object} param
     * @param {Object} param.element
     */
    async _refreshSection({element}) {
        if (this._isCourseItemDragging()) {
            this.#deferredCourseSectionRefreshes.add(String(element.id));
            return;
        }

        this._refreshCopySectionOptions();

        if (this.showSharingCartBasket && this.canBackup) {
            let backupButton = await this.getBackupToSharingCartButton();
            if (this._isCourseItemDragging()) {
                this.#deferredCourseSectionRefreshes.add(String(element.id));
                return;
            }

            const sectionTitle = document.querySelector(
                '.course-content [data-for="section_title"] .inplaceeditable[data-itemid="' + element.id + '"]'
            );
            if (sectionTitle) {
                const hasBackupButton = sectionTitle.parentElement.querySelector('.add_to_sharing_cart');
                if (!hasBackupButton) {
                    sectionTitle.after(backupButton);

                    backupButton.addEventListener(
                        'click',
                        (e) => {
                            if (e.currentTarget.classList.contains('disabled')) {
                                return;
                            }
                            this.block.addSectionBackupToSharingCart(element.id);
                        }
                    );
                }
                backupButton = sectionTitle.parentElement.querySelector('.add_to_sharing_cart');

                const disabled = element.cmlist.length === 0;
                backupButton.classList.toggle('disabled', disabled);
                backupButton.title = disabled ?
                    await getString('no_course_modules_in_section_description', 'block_sharing_cart') :
                    '';
            }
        }

        if (this.showSharingCartBasket && this.canBackup) {
            // A subsection's copyability depends on its delegated section contents,
            // which can change without the subsection course-module state changing.
            this.reactive.state.cm.forEach((courseModule) => {
                if (String(courseModule.delegatesectionid) === String(element.id)) {
                    this._refreshCourseModule({element: courseModule});
                }
            });
        }
    }

    /**
     * Refresh the course module.
     * @param {Object} param
     * @param {Object} param.element
     */
    async _refreshCourseModule({element}) {
        if (!this.showSharingCartBasket || !this.canBackup) {
            return;
        }

        const courseModuleId = String(element.id);
        if (this._isCourseItemDragging()) {
            this.#deferredCourseModuleRefreshes.add(courseModuleId);
            return;
        }

        const activeRefresh = this.#courseModuleRefreshes.get(courseModuleId);
        if (activeRefresh) {
            this.#queuedCourseModuleRefreshes.add(courseModuleId);
            await activeRefresh;
            return;
        }

        const refresh = this._refreshCourseModuleNow({element});
        this.#courseModuleRefreshes.set(courseModuleId, refresh);
        try {
            await refresh;
        } finally {
            if (this.#courseModuleRefreshes.get(courseModuleId) === refresh) {
                this.#courseModuleRefreshes.delete(courseModuleId);
                if (this.#queuedCourseModuleRefreshes.delete(courseModuleId)) {
                    await this._refreshCourseModule({element});
                }
            }
        }
    }

    /**
     * Perform one basket refresh for a course module.
     * @param {Object} param
     * @param {Object} param.element
     */
    async _refreshCourseModuleNow({element}) {
        if (this.showSharingCartBasket && this.canBackup) {
            const backupButton = await this.getBackupToSharingCartButton();
            if (this._isCourseItemDragging()) {
                this.#deferredCourseModuleRefreshes.add(String(element.id));
                return;
            }

            // Always resolve the exact CM from its state ID. Walking up from its
            // action menu can land on an ancestor subsection and misclassify nested
            // activities (for example, a choice inside a subsection).
            const courseModuleItem = document.querySelector(
                '.course-content [data-for="cmitem"][data-id="' + element.id + '"]'
            ) ?? document.querySelector('[data-for="cmitem"][data-id="' + element.id + '"]');
            const courseModuleActionMenu = courseModuleItem?.querySelector('.cm_action_menu[data-cmid="' + element.id + '"]');
            const isPxgrid = document.body.classList.contains('format-pxgrid');
            const activityTitle = isPxgrid ?
                courseModuleItem?.querySelector('.activity-name-area .activityname') : null;

            // Subsections can also get their basket from the delegated-section
            // renderer. Only count buttons owned by this CM, not nested CMs.
            const courseModuleButtons = courseModuleItem ?
                [...courseModuleItem.querySelectorAll('.add_to_sharing_cart')].filter((button) =>
                    button.closest('[data-for="cmitem"]') === courseModuleItem
                ) : [];
            const registeredButtons = [...document.querySelectorAll(
                '.course-content .add_to_sharing_cart[data-cmid="' + element.id + '"]'
            )];
            const existingButton = courseModuleButtons[0] ?? registeredButtons[0] ?? null;
            new Set([...courseModuleButtons, ...registeredButtons]).forEach((button) => {
                if (button !== existingButton) {
                    button.remove();
                }
            });
            const buttonContainer = activityTitle ?? courseModuleActionMenu ?? existingButton?.parentElement;

            if (!buttonContainer) {
                setTimeout(() => this._refreshCourseModule({element}), 100);
                return;
            }

            const button = existingButton ?? backupButton;
            button.dataset.cmid = String(element.id);
            if (!existingButton) {
                backupButton.addEventListener(
                    'click',
                    (event) => {
                        if (event.currentTarget.classList.contains('disabled')) {
                            return;
                        }
                        this.block.addCourseModuleBackupToSharingCart(element.id);
                    }
                );
            }

            if (activityTitle) {
                activityTitle.append(button);
            } else if (!existingButton && button.parentElement !== courseModuleActionMenu) {
                courseModuleActionMenu.append(button);
            }

            const isSubsection = element.module === 'subsection' ||
                element.plugin === 'mod_subsection' ||
                Boolean(element.delegatesectionid) ||
                courseModuleItem?.classList.contains('modtype_subsection');
            const delegatedSection = isSubsection && element.delegatesectionid ?
                this.reactive.state.section.get(element.delegatesectionid) : null;
            const disabled = isSubsection && (!delegatedSection || delegatedSection.cmlist.length === 0);
            button.classList.toggle('disabled', disabled);
            button.setAttribute('aria-disabled', String(disabled));
            button.title = disabled ?
                await getString('no_course_modules_in_section_description', 'block_sharing_cart') :
                '';
        }
    }

    /**
     * On dragging section
     * @param {Object} param
     * @param {Object} param.element
     */
    async _onDraggingSection({element}) {
        if (element.dragging) {
            if (this.#draggedSectionLocation?.id !== String(element.id)) {
                this.#draggedSectionLocation = {
                    id: String(element.id),
                    parent: element.section_parent_id ?? element.parentsectionid ?? null,
                    order: element.sort_order ?? element.section ?? element.number ?? null,
                };
            }
            this.block.getElement().classList.add('dragging_item');
            this.block.setDraggedSectionId(element.id);
        } else {
            const startingLocation = this.#draggedSectionLocation;
            this.#draggedSectionLocation = null;
            this.block.getElement().classList.remove('dragging_item');
            this.block.setDraggedSectionId(null);
            const hasMoved = startingLocation && (
                String(startingLocation.parent) !== String(element.section_parent_id ?? element.parentsectionid ?? null) ||
                String(startingLocation.order) !== String(element.sort_order ?? element.section ?? element.number ?? null)
            );
            this._flushDeferredBasketRefreshes(Boolean(hasMoved));
        }
    }

    /**
     * On dragging course module
     * @param {Object} param
     * @param {Object} param.element
     */
    async _onDraggingCourseModule({element}) {
        if (element.dragging) {
            if (this.#draggedCourseModuleLocation?.id !== String(element.id)) {
                this.#draggedCourseModuleLocation = {
                    id: String(element.id),
                    section: element.sectionid ?? null,
                    sectionnumber: element.sectionnumber ?? null,
                };
            }
            this.block.getElement().classList.add('dragging_item');
            this.block.setDraggedCourseModuleId(element.id);
        } else {
            const startingLocation = this.#draggedCourseModuleLocation;
            this.#draggedCourseModuleLocation = null;
            this.block.getElement().classList.remove('dragging_item');
            this.block.setDraggedCourseModuleId(null);
            const hasMoved = startingLocation && (
                String(startingLocation.section) !== String(element.sectionid ?? null) ||
                String(startingLocation.sectionnumber) !== String(element.sectionnumber ?? null)
            );
            this._flushDeferredBasketRefreshes(Boolean(hasMoved));
        }
    }

    /**
     * Check whether course content is currently in a drag operation.
     * @returns {boolean}
     */
    _isCourseItemDragging() {
        return this.block?.getElement()?.classList.contains('dragging_item') ?? false;
    }

    /**
     * Reconcile deferred basket icons once dragging has finished.
     * @param {boolean} hasMoved Whether the dragged item changed location.
     */
    _flushDeferredBasketRefreshes(hasMoved) {
        if (this._isCourseItemDragging()) {
            return;
        }

        const sectionIds = [...this.#deferredCourseSectionRefreshes];
        this.#deferredCourseSectionRefreshes.clear();
        const courseModuleIds = [...this.#deferredCourseModuleRefreshes];
        this.#deferredCourseModuleRefreshes.clear();

        // A drag that starts and ends at the original location only changes the
        // transient drag presentation. Do not refresh all descendant baskets.
        if (!hasMoved) {
            return;
        }

        sectionIds.forEach((sectionId) => this._refreshSectionWhenReady(sectionId));
        courseModuleIds.forEach((courseModuleId) => this._refreshCourseModuleWhenReady(courseModuleId));
    }
}
