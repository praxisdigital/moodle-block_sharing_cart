import {useEffect, useRef, useState} from 'react';

type DropTargetProps = {
    message: string;
};

/**
 * Renders the course-to-Sharing-Cart drop target and tracks valid drag-over state.
 *
 * @param props Component properties.
 * @returns The Sharing Cart drop target.
 */
export default function DropTarget({message}: DropTargetProps) {
    const targetRef = useRef<HTMLDivElement>(null);
    const [draggingOver, setDraggingOver] = useState(false);

    useEffect(() => {
        const target = targetRef.current;
        const block = target?.closest('.block.block_sharing_cart');
        if (!target || !block) {
            return;
        }

        const hasCourseItemDrag = () => block.classList.contains('dragging_item');
        const showValidTarget = () => {
            if (hasCourseItemDrag()) {
                setDraggingOver(true);
            }
        };
        const hideValidTarget = () => setDraggingOver(false);
        const handleDragLeave = (event: DragEvent) => {
            if (!hasCourseItemDrag()) {
                hideValidTarget();
                return;
            }

            const nextTarget = event.relatedTarget;
            if (nextTarget instanceof Node && block.contains(nextTarget)) {
                return;
            }

            const pointerTarget = document.elementFromPoint(event.clientX, event.clientY);
            if (pointerTarget && block.contains(pointerTarget)) {
                return;
            }

            hideValidTarget();
        };

        block.addEventListener('dragenter', showValidTarget);
        block.addEventListener('dragover', showValidTarget);
        block.addEventListener('dragleave', handleDragLeave);
        block.addEventListener('drop', hideValidTarget);
        document.addEventListener('dragend', hideValidTarget);

        return () => {
            block.removeEventListener('dragenter', showValidTarget);
            block.removeEventListener('dragover', showValidTarget);
            block.removeEventListener('dragleave', handleDragLeave);
            block.removeEventListener('drop', hideValidTarget);
            document.removeEventListener('dragend', hideValidTarget);
        };
    }, []);

    return (
        <div
            ref={targetRef}
            className={`drop_here_text${draggingOver ? ' dragging_item_over' : ''}`}
            role="status"
            aria-live="polite"
        >
            <i className="fa fa-arrow-down" aria-hidden="true" />
            <strong>{message}</strong>
        </div>
    );
}
