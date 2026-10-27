var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });
import { jsxDEV } from "react/jsx-dev-runtime";
import { useState } from "react";
import { fetchOne } from "@moodle/lms/core/ajax";
function BasketIconsNotice({ message, dismissLabel, dismissError }) {
  const [dismissed, setDismissed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const dismissNotice = /* @__PURE__ */ __name(() => {
    if (saving) {
      return;
    }
    setSaving(true);
    fetchOne({
      methodname: "core_user_update_user_preferences",
      args: {
        preferences: [{
          type: "block_sharing_cart_basket_notice_dismissed",
          value: "1"
        }]
      }
    }).then(() => {
      setDismissed(true);
    }).catch(() => {
      setError(dismissError);
      setSaving(false);
    });
  }, "dismissNotice");
  if (dismissed) {
    return null;
  }
  return /* @__PURE__ */ jsxDEV("div", { className: "alert alert-info alert-dismissible mb-3 sharing-cart-basket-notice", role: "status", children: [
    /* @__PURE__ */ jsxDEV("span", { children: message }, void 0, false, {
      fileName: "public/blocks/sharing_cart/js/esm/src/BasketIconsNotice.tsx",
      lineNumber: 49,
      columnNumber: 13
    }, this),
    /* @__PURE__ */ jsxDEV(
      "button",
      {
        type: "button",
        className: "btn-close",
        "aria-label": dismissLabel,
        disabled: saving,
        onClick: dismissNotice
      },
      void 0,
      false,
      {
        fileName: "public/blocks/sharing_cart/js/esm/src/BasketIconsNotice.tsx",
        lineNumber: 50,
        columnNumber: 13
      },
      this
    ),
    error && /* @__PURE__ */ jsxDEV("div", { className: "text-danger mt-2", role: "alert", children: error }, void 0, false, {
      fileName: "public/blocks/sharing_cart/js/esm/src/BasketIconsNotice.tsx",
      lineNumber: 57,
      columnNumber: 23
    }, this)
  ] }, void 0, true, {
    fileName: "public/blocks/sharing_cart/js/esm/src/BasketIconsNotice.tsx",
    lineNumber: 48,
    columnNumber: 9
  }, this);
}
__name(BasketIconsNotice, "BasketIconsNotice");
export {
  BasketIconsNotice as default
};
//# sourceMappingURL=BasketIconsNotice.dev.js.map
