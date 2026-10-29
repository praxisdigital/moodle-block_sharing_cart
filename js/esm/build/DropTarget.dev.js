var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
import { jsxDEV } from "react/jsx-dev-runtime";
import { useEffect, useRef, useState } from "react";
function DropTarget({ message }) {
  const targetRef = useRef(null);
  const [draggingOver, setDraggingOver] = useState(false);
  useEffect(() => {
    const target = targetRef.current;
    const block = target?.closest(".block.block_sharing_cart");
    if (!target || !block) {
      return;
    }
    const hasCourseItemDrag = /* @__PURE__ */ __name(() => block.classList.contains("dragging_item"), "hasCourseItemDrag");
    const showValidTarget = /* @__PURE__ */ __name(() => {
      if (hasCourseItemDrag()) {
        setDraggingOver(true);
      }
    }, "showValidTarget");
    const hideValidTarget = /* @__PURE__ */ __name(() => setDraggingOver(false), "hideValidTarget");
    const handleDragLeave = /* @__PURE__ */ __name((event) => {
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
    }, "handleDragLeave");
    block.addEventListener("dragenter", showValidTarget);
    block.addEventListener("dragover", showValidTarget);
    block.addEventListener("dragleave", handleDragLeave);
    block.addEventListener("drop", hideValidTarget);
    document.addEventListener("dragend", hideValidTarget);
    return () => {
      block.removeEventListener("dragenter", showValidTarget);
      block.removeEventListener("dragover", showValidTarget);
      block.removeEventListener("dragleave", handleDragLeave);
      block.removeEventListener("drop", hideValidTarget);
      document.removeEventListener("dragend", hideValidTarget);
    };
  }, []);
  return /* @__PURE__ */ jsxDEV(
    "div",
    {
      ref: targetRef,
      className: `drop_here_text${draggingOver ? " dragging_item_over" : ""}`,
      role: "status",
      "aria-live": "polite",
      children: [
        /* @__PURE__ */ jsxDEV("i", { className: "fa fa-arrow-down", "aria-hidden": "true" }, void 0, false, {
          fileName: "public/blocks/sharing_cart/js/esm/src/DropTarget.tsx",
          lineNumber: 72,
          columnNumber: 13
        }, this),
        /* @__PURE__ */ jsxDEV("strong", { children: message }, void 0, false, {
          fileName: "public/blocks/sharing_cart/js/esm/src/DropTarget.tsx",
          lineNumber: 73,
          columnNumber: 13
        }, this)
      ]
    },
    void 0,
    true,
    {
      fileName: "public/blocks/sharing_cart/js/esm/src/DropTarget.tsx",
      lineNumber: 66,
      columnNumber: 9
    },
    this
  );
}
__name(DropTarget, "DropTarget");
export {
  DropTarget as default
};
//# sourceMappingURL=DropTarget.dev.js.map
