export default class EventHandler {
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
     * @param {Boolean} canBackupUserdata
     * @param {Boolean} canAnonymizeUserdata
     * @param {Boolean} canBackup
     * @param {Boolean} showSharingCartBasket
     * @param {Boolean} showCopiesQueuedSegmentWhenEmpty
     */
    onLoad(canBackupUserdata, canAnonymizeUserdata, canBackup, showSharingCartBasket, showCopiesQueuedSegmentWhenEmpty) {
        return this.setupBlock(
            canBackupUserdata,
            canAnonymizeUserdata,
            canBackup,
            showSharingCartBasket,
            showCopiesQueuedSegmentWhenEmpty
        );
    }

    /**
     * @param {Boolean} canBackupUserdata
     * @param {Boolean} canAnonymizeUserdata
     * @param {Boolean} canBackup
     * @param {Boolean} showSharingCartBasket
     * @param {Boolean} showCopiesQueuedSegmentWhenEmpty
     * @returns {{course: CourseElement, block: BlockElement, queue: QueueElement}}
     */
    setupBlock(canBackupUserdata, canAnonymizeUserdata, canBackup, showSharingCartBasket, showCopiesQueuedSegmentWhenEmpty) {
        const block = document.querySelector('.block.block_sharing_cart');

        const blockElement = this.#baseFactory.block().element(
            block,
            canBackupUserdata,
            canAnonymizeUserdata,
            canBackup,
            showSharingCartBasket,
            showCopiesQueuedSegmentWhenEmpty
        );
        return blockElement.addEventListeners();
    }
}
