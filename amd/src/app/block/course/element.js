import {getCurrentCourseEditor} from "core_courseformat/courseeditor";

/**
 * @typedef {import("core_courseformat/factory").BaseFactory} BaseFactory
 * @typedef {import("core_courseformat/local/courseeditor/courseeditor").CourseEditor} CourseEditor
 */

/**
 * @typedef Section
 * @property {number} id
 * @property {number} section
 * @property {number} number
 * @property {string} title
 * @property {boolean} hassummary
 * @property {string} rawtitle
 * @property {string[]} cmlist
 * @property {boolean} visible
 * @property {string} sectionurl
 * @property {boolean} current
 * @property {boolean} indexcollapsed
 * @property {boolean} contentcollapsed
 * @property {boolean} hasrestrictions
 * @property {boolean} bulkeditable
 * @property {string|null} component
 * @property {number|null} itemid
 */

/**
 * Where a sharing cart item dropped into the course should be restored.
 *
 * @typedef DropTarget
 * @property {number} sectionId The section to restore into, or the section the new section is inserted after.
 * @property {number} beforeCourseModuleId Course module to place the restored activities before (0 = end of section).
 * @property {boolean} asNewSection Whether a new section is created after sectionId.
 * @property {HTMLElement} indicator The element that shows the drop position.
 * @property {string} indicatorClass The core drop zone class to put on the indicator.
 */

/**
 * @typedef CourseModule
 * @property {number} id
 * @property {string} anchor
 * @property {string} name
 * @property {boolean} visible
 * @property {boolean} stealth
 * @property {string} sectionid
 * @property {number} sectionnumber
 * @property {boolean} uservisible
 * @property {boolean} hascmrestrictions
 * @property {string} modname
 * @property {number} indent
 * @property {number} groupmode
 * @property {string} module
 * @property {string} plugin
 * @property {boolean} delegatesection
 * @property {boolean} accessvisible
 * @property {string} url
 * @property {boolean} istrackeduser
 * @property {boolean} allowstealth
 */

export default class CourseElement {
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

    /**
     * @type {HTMLElement|null}
     */
    #clipboard = null;

    /**
     * @type {AbortController}
     */
    #clipboardTargetListenerAbortController = new AbortController();

    /**
     * @type {ItemElement|null}
     */
    #draggedItem = null;

    /**
     * @type {DropTarget|null}
     */
    #dropTarget = null;

    /**
     * @type {AbortController}
     */
    #dragListenerAbortController = new AbortController();

    /**
     * @type {CourseEditor}
     */
    reactive;

    /**
     * @param {BaseFactory} baseFactory
     * @param {BlockElement} blockElement
     * @param {HTMLElement} element
     */
    constructor(baseFactory, blockElement, element) {
        this.#baseFactory = baseFactory;
        this.#blockElement = blockElement;
        this.#element = element;
        this.reactive = getCurrentCourseEditor();
    }

    async renderClipboard() {
        this.#clipboard = await this.#baseFactory.moodle().template().createElementFromTemplate(
            'block_sharing_cart/block/course/clipboard',
            {}
        );

        this.#element.prepend(this.#clipboard);

        const clearClipboardButton = this.#clipboard.querySelector('[data-action="clear-clipboard"]');
        clearClipboardButton.addEventListener(
            'click',
            this.onClearClipboard.bind(this)
        );
    }

    /**
     * @param {Event} e
     */
    onClearClipboard(e) {
        e.preventDefault();
        e.stopPropagation();

        this.clearClipboard();
    }

    clearClipboard() {
        this.#clipboard?.classList.add('d-none');
        this.clearClipboardTargets();

        this.#blockElement.clearClipboard();
    }

    async reRenderClipboard() {
        this.clearClipboard();
        await this.renderClipboard();
    }

    /**
     * @param {ItemElement} item
     */
    updateClipboard(item) {
        this.#clipboard.classList.add('d-none');

        const clipboardItemInfo = this.#clipboard.querySelector('.info');
        clipboardItemInfo.innerHTML = item.getItemInfo().innerHTML;

        this.#clipboard.classList.remove('d-none');
    }

    /**
     * @param {ItemElement} item
     */
    async updateClipboardTargets(item) {
        const element = await this.#baseFactory.moodle().template().createElementFromTemplate(
            'block_sharing_cart/block/course/clipboard_target',
            {}
        );

        this.#clipboardTargetListenerAbortController.abort();
        this.#clipboardTargetListenerAbortController = new AbortController();

        this.#element.querySelectorAll('[data-for="cmlist"],[data-for="course_sectionlist"]').forEach((section) => {

            let clipboardTarget = null;
            let sectionId = null;

            if (section.matches('[data-for="course_sectionlist"]')) {

                const courseSectionsElementList = section.querySelectorAll('.section-item ul[data-for="cmlist"]');

                if (!courseSectionsElementList) {
                    return;
                }

                courseSectionsElementList.forEach((courseSectionElement) => {

                    if (!this.isSectionClipboardTargetEligible(courseSectionElement, item)) {
                        return;
                    }

                    clipboardTarget = courseSectionElement.querySelector('.clipboard_target') ?? element.cloneNode(true);
                    courseSectionElement.prepend(clipboardTarget);
                    sectionId = courseSectionElement.closest('[data-for="section"]').dataset.id;

                    clipboardTarget.classList.remove('hidden');
                    clipboardTarget.parentElement.classList.remove('hidden');
                    clipboardTarget.addEventListener(
                        'click',
                        this.#blockElement.confirmImportBackupFromSharingCart.bind(this.#blockElement, item, sectionId),
                        {
                            signal: this.#clipboardTargetListenerAbortController.signal
                        }
                    );
                });

                return;
            }

            if (!this.isSectionClipboardTargetEligible(section, item)) {
                return;
            }

            clipboardTarget = section.querySelector('.clipboard_target') ?? element.cloneNode(true);
            section.prepend(clipboardTarget);
            sectionId = section.closest('[data-for="section"]').dataset.id;

            clipboardTarget.classList.remove('hidden');
            clipboardTarget.parentElement.classList.remove('hidden');
            clipboardTarget.addEventListener(
                'click',
                this.#blockElement.confirmImportBackupFromSharingCart.bind(this.#blockElement, item, sectionId),
                {
                    signal: this.#clipboardTargetListenerAbortController.signal
                }
            );
        });
    }

    /**
     * Checks whether the section is allowed to have a clipboardTarget button prepended.
     * For example, subsections are not eligible as a clipboard target if the clipboard is a subsection.
     * Subsections cannot nest subsections.
     * @param {Element} section
     * @param {ItemElement} item
     * @returns {boolean}
     */
    isSectionClipboardTargetEligible(section, item) {

        // Has already been made a clipboardTarget.
        if (section.querySelector('.clipboard_target')) {
            return false;
        }

        // If the item is nested under a subsection in the clipboard.
        // And the clipboardTarget is a subsection. (That is not allowed)
        if (item.isNestedUnderSubsection() && section.closest(".subsection")) {
            return false;
        }

        // Activities can always be inserted into any clipboardTarget.
        if (!item.isSubsection() && !item.isSection()) {
            return true;
        }

        // No section nor subsection can be inserted into an activity clipboardTarget.
        if (section.closest('[data-region="activity-card"]')) {
            return false;
        }

        return true;
    }

    /**
     * Starts listening for a sharing cart item being dragged over the course.
     * The drop position is shown with the drop-up/drop-down classes core uses when moving activities and sections,
     * so any theme that styles core's course drag and drop styles this one too.
     * @param {ItemElement} item
     */
    startItemDrag(item) {
        if (!this.#element) {
            return;
        }

        this.endItemDrag();
        this.#draggedItem = item;

        const options = {signal: this.#dragListenerAbortController.signal};
        this.#element.addEventListener('dragover', this.#onItemDragOver.bind(this), options);
        this.#element.addEventListener('dragleave', this.#onItemDragLeave.bind(this), options);
        this.#element.addEventListener('drop', this.#onItemDrop.bind(this), options);

        document.body.classList.add('dragging');
    }

    /**
     * Builds a drag image that looks like the activity (or section) being dragged in the course, the way core's own
     * drag image is the activity element itself. It is a stripped clone of an element already on the page, inserted
     * next to it so the theme and format styles apply, and must be removed again once the drag has started.
     * @param {ItemElement} item
     * @returns {HTMLElement|null}
     */
    createDragImage(item) {
        if (!this.#element) {
            return null;
        }

        const source = item.isSection() ? this.#getSectionDragImageSource() : this.#getActivityDragImageSource(item);
        if (!source) {
            return null;
        }

        const image = source.cloneNode(true);
        image.removeAttribute('data-for');
        image.removeAttribute('data-id');
        image.classList.remove('drop-up', 'drop-down', 'dragging', 'dragover');
        image.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
        image.querySelectorAll([
            '.action-menu',
            '.cm_action_menu',
            '.add_to_sharing_cart',
            '.activity-altcontent',
            '.activity-information',
            '.activity-completion',
            '.activity-dates',
            '.availabilityinfo',
            '.description',
            '[data-region="activity-information"]',
            '[data-for="cmlist"]',
        ].join(',')).forEach((element) => element.remove());

        if (item.isSection()) {
            image.querySelectorAll('.section-item > :not(.course-section-header)').forEach((element) => element.remove());
            const title = image.querySelector('[data-for="section_title"], .sectionname');
            if (title) {
                title.textContent = item.getItemName();
            }
        } else {
            const name = image.querySelector('.instancename');
            if (name) {
                name.textContent = item.getItemName();
            }
            // A different module type was cloned, so at least show the item's own icon.
            const icon = image.querySelector('img.activityicon');
            const itemIcon = item.getItemIcon();
            if (icon && !source.classList.contains(`modtype_${item.getModuleName()}`) && itemIcon?.src) {
                icon.src = itemIcon.src;
            }
        }

        Object.assign(image.style, {
            position: 'absolute',
            top: '-10000px',
            left: '-10000px',
            width: `${source.getBoundingClientRect().width}px`,
            pointerEvents: 'none',
        });
        source.after(image);

        return image;
    }

    /**
     * An activity of the same type if the course has one, so icon and colours match, otherwise any plain activity.
     * @param {ItemElement} item
     * @returns {HTMLElement|null}
     */
    #getActivityDragImageSource(item) {
        return this.#element.querySelector(`[data-for="cmitem"].modtype_${CSS.escape(item.getModuleName())}`) ??
            this.#element.querySelector('[data-for="cmitem"]:not(.modtype_subsection):not(.modtype_label)');
    }

    /**
     * A top level section other than section 0, whose header is styled like a normal section.
     * @returns {HTMLElement|null}
     */
    #getSectionDragImageSource() {
        const sections = [...this.#element.querySelectorAll('[data-for="course_sectionlist"] > [data-for="section"]')];

        return sections.find((section) => section.dataset.number !== '0') ?? sections[0] ?? null;
    }

    endItemDrag() {
        this.#dragListenerAbortController.abort();
        this.#dragListenerAbortController = new AbortController();
        this.#setDropTarget(null);
        this.#draggedItem = null;
        document.body.classList.remove('dragging');
    }

    /**
     * @param {DragEvent} e
     */
    #onItemDragOver(e) {
        const target = this.#resolveDropTarget(e);
        this.#setDropTarget(target);

        if (!target) {
            e.dataTransfer.dropEffect = 'none';
            return;
        }

        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    }

    /**
     * @param {DragEvent} e
     */
    #onItemDragLeave(e) {
        // Only when leaving the course content, not when moving between its children.
        if (!e.relatedTarget || !this.#element.contains(e.relatedTarget)) {
            this.#setDropTarget(null);
        }
    }

    /**
     * @param {DragEvent} e
     */
    async #onItemDrop(e) {
        const item = this.#draggedItem;
        const target = this.#resolveDropTarget(e);
        if (!item || !target) {
            return;
        }

        this.endItemDrag();

        await this.#blockElement.confirmImportBackupFromSharingCart(item, target.sectionId, e, target);
    }

    /**
     * @param {DropTarget|null} target
     */
    #setDropTarget(target) {
        const current = this.#dropTarget;
        if (current?.indicator !== target?.indicator || current?.indicatorClass !== target?.indicatorClass) {
            current?.indicator.classList.remove(current.indicatorClass);
            target?.indicator.classList.add(target.indicatorClass);
        }

        this.#dropTarget = target;
    }

    /**
     * @param {DragEvent} e
     * @returns {DropTarget|null}
     */
    #resolveDropTarget(e) {
        if (!this.#draggedItem || !(e.target instanceof Element)) {
            return null;
        }

        if (this.#draggedItem.isSection()) {
            return this.#resolveSectionDropTarget(e);
        }

        return this.#resolveActivityDropTarget(e.target.closest('[data-for="cmitem"], [data-for="section"]'), e.clientY);
    }

    /**
     * Activities go before or after the hovered activity, or at the end of the hovered section.
     * @param {Element|null} node The innermost activity or section under the pointer.
     * @param {number} clientY
     * @returns {DropTarget|null}
     */
    #resolveActivityDropTarget(node, clientY) {
        if (!node || !this.#element.contains(node)) {
            return null;
        }

        const isCourseModule = node.matches('[data-for="cmitem"]');
        const sectionElement = isCourseModule ? node.parentElement.closest('[data-for="section"]') : node;
        if (!sectionElement) {
            return null;
        }

        if (!this.#canSectionTakeItem(sectionElement, this.#draggedItem)) {
            // E.g. a subsection over another subsection: offer the position around that subsection instead.
            return this.#resolveActivityDropTarget(sectionElement.parentElement.closest('[data-for="cmitem"]'), clientY);
        }

        const sectionId = Number.parseInt(sectionElement.dataset.id);

        if (!isCourseModule) {
            return {
                sectionId,
                beforeCourseModuleId: 0,
                asNewSection: false,
                indicator: this.#getSectionEndIndicator(sectionElement),
                indicatorClass: 'drop-down',
            };
        }

        const rect = node.getBoundingClientRect();
        if (clientY < rect.top + rect.height / 2) {
            return {
                sectionId,
                beforeCourseModuleId: Number.parseInt(node.dataset.id),
                asNewSection: false,
                indicator: node,
                indicatorClass: 'drop-up',
            };
        }

        let next = node.nextElementSibling;
        while (next && !next.matches('[data-for="cmitem"]')) {
            next = next.nextElementSibling;
        }

        return {
            sectionId,
            beforeCourseModuleId: next ? Number.parseInt(next.dataset.id) : 0,
            asNewSection: false,
            indicator: node,
            indicatorClass: 'drop-down',
        };
    }

    /**
     * Sections are inserted as a new section between the top level sections.
     * @param {DragEvent} e
     * @returns {DropTarget|null}
     */
    #resolveSectionDropTarget(e) {
        const selector = '[data-for="course_sectionlist"] > [data-for="section"]';
        const sections = [...this.#element.querySelectorAll(selector)];
        if (sections.length === 0) {
            return null;
        }

        // The hovered section, or the first one below the pointer when it is over the gap between two sections.
        const section = e.target.closest(selector) ??
            sections.find((element) => element.getBoundingClientRect().bottom > e.clientY) ??
            sections[sections.length - 1];
        const sectionId = Number.parseInt(section.dataset.id);

        const rect = section.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            // Nothing can go before section 0, so the upper half of it falls through to "after section 0".
            const previousSectionId = this.#getPreviousSectionId(sectionId);
            if (previousSectionId) {
                return {
                    sectionId: previousSectionId,
                    beforeCourseModuleId: 0,
                    asNewSection: true,
                    indicator: section,
                    indicatorClass: 'drop-up',
                };
            }
        }

        return {
            sectionId,
            beforeCourseModuleId: 0,
            asNewSection: true,
            indicator: section,
            indicatorClass: 'drop-down',
        };
    }

    /**
     * Mirrors isSectionClipboardTargetEligible: subsections and their children cannot go into a subsection.
     * @param {Element} sectionElement
     * @param {ItemElement} item
     * @returns {boolean}
     */
    #canSectionTakeItem(sectionElement, item) {
        const section = this.reactive.state.section.get(sectionElement.dataset.id);
        if (!section?.component) {
            return true;
        }

        return !item.isSubsection() && !item.isNestedUnderSubsection();
    }

    /**
     * The element core marks when an activity is dropped at the end of a section.
     * @param {Element} sectionElement
     * @returns {Element}
     */
    #getSectionEndIndicator(sectionElement) {
        const courseModuleList = [...sectionElement.querySelectorAll('[data-for="cmlist"]')]
            .find((list) => list.closest('[data-for="section"]') === sectionElement);
        const courseModules = [...(courseModuleList?.children ?? [])]
            .filter((element) => element.matches('[data-for="cmitem"]'));

        return courseModules[courseModules.length - 1] ??
            sectionElement.querySelector('[data-for="sectioninfo"]') ??
            sectionElement;
    }

    /**
     * The id of the top level section before the given one, from the course state so it also works on
     * single section pages where the previous section is not rendered.
     * @param {number} sectionId
     * @returns {number|null}
     */
    #getPreviousSectionId(sectionId) {
        const current = this.reactive.state.section.get(sectionId);
        if (!current) {
            return null;
        }

        let previous = null;
        this.reactive.state.section.forEach((section) => {
            if (section.component || section.number >= current.number) {
                return;
            }
            if (!previous || section.number > previous.number) {
                previous = section;
            }
        });

        return previous ? Number.parseInt(previous.id) : null;
    }

    /**
     * @param {Number} sectionId
     * @returns {String}
     */
    getSectionName(sectionId) {
        const section = this.reactive.state.section.get(sectionId);

        if (section.title) {
            return section.title;
        }

        return 'Unknown';
    }

    /**
     * @param {Number} sectionId
     * @returns {Array<string>}
     */
    getSectionCourseModules(sectionId) {
        return this.reactive.state.section.get(sectionId).cmlist;
    }

    /**
     * @param {number|string} courseModuleId
     * @returns {CourseModule|null}
     */
    getCourseModule(courseModuleId) {
        return this.reactive.state.cm.get(courseModuleId);
    }

    /**
     * @param {String} courseModuleId
     * @returns {String}
     */
    getCourseModuleName(courseModuleId) {
        const courseModule = this.reactive.state.cm.get(courseModuleId);

        return courseModule.name ?? 'Unknown';
    }

    /**
     * @param {number|string} sectionId
     * @param {string} type
     * @returns {boolean}
     */
    hasSectionCourseModuleType(sectionId, type) {
        let cms = this.getSectionCourseModules(sectionId);
        if (!cms) {
            return false;
        }
        for (const id of cms) {
            if (this.isCourseModuleTypeById(id, type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param {number|string} courseModuleId
     * @param {string} type
     * @returns {boolean}
     */
    isCourseModuleTypeById(courseModuleId, type) {
        const courseModule = this.getCourseModule(courseModuleId);
        if (!courseModule) {
            return false;
        }
        return courseModule?.module === type;
    }

    /**
     * @returns {NodeListOf<HTMLElement>}
     */
    getClipboardTargets() {
        return this.#element.querySelectorAll('.clipboard_target');
    }

    clearClipboardTargets() {
        this.getClipboardTargets().forEach((target) => {
            target.remove();
        });
    }

    /**
     * @param {ItemElement} item
     */
    async setClipboard(item) {
        if (!this.#clipboard) {
            await this.renderClipboard();
        } else {
            await this.reRenderClipboard();
        }

        this.updateClipboard(item);
        await this.updateClipboardTargets(item);
    }
}
