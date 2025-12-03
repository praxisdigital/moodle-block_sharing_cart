import QueueElement from "./element";

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
     * @param {boolean} showCopiesQueuedSegmentWhenEmpty
     * @returns {QueueElement}
     */
    element(blockElement, element, showCopiesQueuedSegmentWhenEmpty) {
        return new QueueElement(this.#baseFactory, blockElement, element, showCopiesQueuedSegmentWhenEmpty);
    }
}
