// eslint-disable-next-line no-unused-vars
import BaseFactory from '../factory';
import ItemElement from "./element";

export default class Factory {
    /**
     * @type {BaseFactory}
     */
    #baseFactory;

    /**
     * @param {BaseFactory} baseFactory
     */
    constructor(baseFactory) {
        this.#baseFactory = baseFactory;
    }

    /**
     * @param {BlockElement} blockElement
     * @param {HTMLElement} element
     * @returns {ItemElement}
     */
    element(blockElement, element) {
        return new ItemElement(this.#baseFactory, blockElement, element);
    }
}