import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import Notification from "core/notification";
import {get_strings} from "core/str";
import Ajax from "core/ajax";

const polls = [];

export default class ItemElement {
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
     * @param {BaseFactory} baseFactory
     * @param {BlockElement} blockElement
     * @param {HTMLElement} element
     */
    constructor(baseFactory, blockElement, element) {
        this.#baseFactory = baseFactory;
        this.#blockElement = blockElement;
        this.#element = element;

        if (this.#element.dataset.status === '0') {
            this.#pollItem();
        }

        this.#addEventListeners();
    }

    #pollItem(currentTry = 0, retries = -1, uuid = null) {

        if (uuid === null) {
            uuid = crypto.getRandomValues(new Uint32Array(1))[0]

            if (polls[this.getItemId()]) {
                return;
            }

            polls[this.getItemId()] = uuid;
        } else if (polls[this.getItemId()] !== uuid) {
            return;
        }

        currentTry += 1;

        if (retries !== -1 && currentTry >= retries) {
            return;
        }

        Ajax.call([{
            methodname: 'block_sharing_cart_get_item_from_sharing_cart',
            args: {
                item_id: this.getItemId(),
                course_id: M.cfg.courseId
            },
            done: async(item) => {
                const actionsContainer = this.#element.querySelector(':scope > .item-body .sharing_cart_item_actions');
                const runNowButton = actionsContainer?.querySelector('[data-action="run_now"]');
                if (!runNowButton && item.show_run_now) {
                    await this.#blockElement.renderItem(item);
                }

                if (item.status === 0) {
                    // Cap the timeout at 10 seconds
                    const timeOut = currentTry > 10 ? 10000 : currentTry * 1000;

                    setTimeout(() => {
                        this.#pollItem(currentTry, retries, uuid);
                    }, timeOut);
                    return;
                }

                // Remove the item from the polls array
                polls.splice(this.getItemId(), 1);

                await this.#blockElement.renderItem(item);
            },
            fail: (data) => {
                Notification.exception(data);
            }
        }]);
    }

    #addEventListeners() {
        this.#element.querySelector('.info').addEventListener('click', this.toggleCollapseRecursively.bind(this));

        const checkbox = this.#element.querySelector('input[data-action="bulk_select"][type="checkbox"]');
        checkbox?.addEventListener('click', (e) => {
            e.stopImmediatePropagation();

            this.#blockElement.updateSelectAllState();
            this.#blockElement.updateBulkDeleteButtonState();
        });

        const actionsContainer = this.#element.querySelector(':scope > .item-body .sharing_cart_item_actions');

        actionsContainer?.querySelector('[data-action="delete"]')?.addEventListener(
            'click',
            this.confirmDeleteItem.bind(this)
        );
        actionsContainer?.querySelector('[data-action="copy_to_course"]')?.addEventListener(
            'click',
            this.copyToCourse.bind(this)
        );
        actionsContainer?.querySelector('[data-action="run_now"]')?.addEventListener(
            'click',
            this.runNow.bind(this)
        );
    }

    async copyToCourse(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        await this.#blockElement.setClipboard(this);
    }

    async runNow(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const currentTarget = e.currentTarget;
        currentTarget.disabled = true;

        Ajax.call([{
            methodname: 'block_sharing_cart_run_task_now',
            args: {
                task_id: currentTarget?.dataset?.taskId ?? null,
            },
            done: async () => {
                currentTarget.remove();
                this.#pollItem();
            },
            fail: (data) => {
                Notification.exception(data);
                currentTarget.disabled = false;
            }
        }]);
    }

    async confirmDeleteItem(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const strings = await get_strings([
            {
                key: 'delete_item',
                component: 'block_sharing_cart',
            },
            {
                key: 'confirm_delete_item',
                component: 'block_sharing_cart',
            },
            {
                key: 'delete',
                component: 'core',
            },
            {
                key: 'cancel',
                component: 'core',
            }
        ]);

        const modal = await ModalFactory.create({
            type: ModalFactory.types.DELETE_CANCEL,
            title: strings[0] + ': "' + this.getItemName().slice(0, 50).trim() + '"',
            body: strings[1],
            buttons: {
                delete: strings[2],
                cancel: strings[3],
            },
            removeOnClose: true,
        });
        modal.getRoot().on(ModalEvents.delete, this.#blockElement.deleteItem.bind(this.#blockElement, this));
        await modal.show();
    }

    /**
     * @return {NodeListOf<HTMLElement>}
     */
    getItemChildrenRecursively() {
        return this.#element.querySelectorAll('.sharing_cart_item');
    }

    /**
     * @return {HTMLElement}
     */
    getItemElement() {
        return this.#element;
    }

    getStatus() {
        return this.#element.dataset.status;
    }

    /**
     * @return {String}
     */
    getItemName() {
        return this.#element.querySelector('.name').innerText;
    }

    /**
     * @return {Number}
     */
    getItemId() {
        return Number.parseInt(this.#element.dataset.itemid);
    }

    /**
     * @return {Number}
     */
    getItemOldInstanceId() {
        return Number.parseInt(this.#element.dataset.oldinstanceid);
    }

    /**
     * @return {HTMLElement}
     */
    getItemInfo() {
        return this.#element.querySelector('.info');
    }

    /**
     * @param {HTMLElement} item
     * @param {Boolean|NULL} collapse
     * @param {Number|NULL} paddingLeftPercent
     */
    toggleCollapse(item, collapse = null, paddingLeftPercent = 0) {

        if(item.style.paddingLeft === ''){
            item.style.paddingLeft = `${paddingLeftPercent}%`;
        }

        if ((item.dataset.type !== 'section' && item.dataset.type !== 'mod_subsection') &&
            item.dataset.status !== '0' &&
            item.dataset.status !== '2') {
            return;
        }

        if (collapse !== null) {
            item.dataset.collapsed = collapse ? 'true' : 'false';
        } else {
            item.dataset.collapsed = item.dataset.collapsed === 'true' ? 'false' : 'true';
        }

        const iconElement = item.querySelector('.info > i');
        if (
            !iconElement.classList.contains('fa-exclamation-triangle') &&
            !iconElement.classList.contains('fa-exclamation-circle')
        ) {

            let classMap;
            if (item.dataset.type === "mod_subsection") {
                classMap = ['fa-bars', 'fa-bars-staggered'];
            } else if (item.dataset.type === "section") {
                classMap = ['fa-folder-o', 'fa-folder-open-o'];
            }

            if (classMap) {
                iconElement.classList.remove(...classMap);

                //Add the correct class based on collapsed state
                const collapsed = item.dataset.collapsed === 'true';
                const classToAdd = collapsed ? classMap[0] : classMap[1];
                iconElement.classList.add(classToAdd);
            }
        }
    }

    isModule() {
        return !this.isSection();
    }

    isSection() {
        return this.#element.dataset.type === 'section';
    }

    isSubsection(){
        return this.#element.dataset.type === 'mod_subsection';
    }

    /**
     * Checks if the item's element is nested under a subsection in the clipboard.
     * @returns {boolean}
     */
    isNestedUnderSubsection(){

        let tempElem = this.getItemElement();
        const maxIterations = 5;
        let i = 0;
        //Loop upwards in the tree, from item.
        while(tempElem != null && i < maxIterations){
            tempElem = tempElem.parentElement
            if(tempElem.classList.contains("sharing_cart_item")) break;
            i++;
        }

        if(!tempElem) return false;

        if(tempElem.dataset.type && tempElem.dataset.type === "mod_subsection") return true;

        return false;
    }

    /**
     * @param {Event} e
     */
    toggleCollapseRecursively(e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        this.toggleCollapse(this.#element);

        let paddingLeftPercentDefault = 12.5;
        this.getItemChildrenRecursively().forEach((item) => {
            this.toggleCollapse(item, this.#element.dataset.collapsed === 'true', paddingLeftPercentDefault);

        });
    }

    remove() {
        this.#element.remove();
    }
}
