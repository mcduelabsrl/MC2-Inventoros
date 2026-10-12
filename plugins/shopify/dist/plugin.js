const ne = window.Inventoros.Vue, { BaseTransition: So, BaseTransitionPropsValidators: bo, Comment: ko, DeprecationTypes: wo, EffectScope: Io, ErrorCodes: zo, ErrorTypeStrings: Co, Fragment: h, KeepAlive: Uo, ReactiveEffect: Ro, Static: $o, Suspense: Po, Teleport: xo, Text: Do, TrackOpTypes: To, Transition: Vo, TransitionGroup: Oo, TriggerOpTypes: Ao, VueElement: Mo, __esModule: No, assertNumber: Eo, callWithAsyncErrorHandling: Ko, callWithErrorHandling: qo, camelize: Fo, capitalize: Ho, cloneVNode: Lo, compatUtils: Bo, compile: jo, computed: E, createApp: Wo, createBlock: P, createCommentVNode: p, createElementBlock: r, createElementVNode: t, createHydrationRenderer: Go, createPropsRestProxy: Qo, createRenderer: Jo, createSSRApp: Xo, createSlots: Yo, createStaticVNode: Zo, createTextVNode: y, createVNode: u, customRef: es, defineAsyncComponent: ts, defineComponent: os, defineCustomElement: ss, defineEmits: ns, defineExpose: is, defineModel: as, defineOptions: rs, defineProps: ls, defineSSRCustomElement: cs, defineSlots: ds, devtools: us, effect: ps, effectScope: hs, getCurrentInstance: _s, getCurrentScope: ys, getCurrentWatcher: ms, getTransitionRawChildren: fs, guardReactiveProps: vs, h: gs, handleError: Ss, hasInjectionContext: bs, hydrate: ks, hydrateOnIdle: ws, hydrateOnInteraction: Is, hydrateOnMediaQuery: zs, hydrateOnVisible: Cs, initCustomFormatter: Us, initDirectivesForSSR: Rs, inject: $s, isMemoSame: Ps, isProxy: xs, isReactive: Ds, isReadonly: Ts, isRef: Vs, isRuntimeOnly: Os, isShallow: As, isVNode: Ms, markRaw: Ns, mergeDefaults: Es, mergeModels: Ks, mergeProps: qs, nextTick: Fs, nodeOps: Hs, normalizeClass: Ls, normalizeProps: Bs, normalizeStyle: js, onActivated: Ws, onBeforeMount: Gs, onBeforeUnmount: Qs, onBeforeUpdate: Js, onDeactivated: Xs, onErrorCaptured: Ys, onMounted: Zs, onRenderTracked: en, onRenderTriggered: tn, onScopeDispose: on, onServerPrefetch: sn, onUnmounted: nn, onUpdated: an, onWatcherCleanup: rn, openBlock: a, patchProp: ln, popScopeId: cn, provide: dn, proxyRefs: un, pushScopeId: pn, queuePostFlushCb: hn, reactive: ie, readonly: _n, ref: B, registerRuntimeCompiler: yn, render: mn, renderList: S, renderSlot: fn, resolveComponent: vn, resolveDirective: gn, resolveDynamicComponent: Sn, resolveFilter: bn, resolveTransitionHooks: kn, setBlockTracking: wn, setDevtoolsHook: In, setTransitionHooks: zn, shallowReactive: Cn, shallowReadonly: Un, shallowRef: Rn, ssrContextKey: $n, ssrUtils: Pn, stop: xn, toDisplayString: o, toHandlerKey: Dn, toHandlers: Tn, toRaw: Vn, toRef: On, toRefs: An, toValue: Mn, transformVNodeArgs: Nn, triggerRef: En, unref: e, useAttrs: Kn, useCssModule: qn, useCssVars: Fn, useHost: Hn, useId: Ln, useModel: Bn, useSSRContext: jn, useShadowRoot: Wn, useSlots: Gn, useTemplateRef: Qn, useTransitionState: Jn, vModelCheckbox: H, vModelDynamic: Xn, vModelRadio: Yn, vModelSelect: L, vModelText: T, vShow: ae, version: Zn, warn: ei, watch: ti, watchEffect: oi, watchPostEffect: si, watchSyncEffect: ni, withAsyncContext: ii, withCtx: d, withDefaults: ai, withDirectives: w, withKeys: ri, withMemo: li, withModifiers: j, withScopeId: ci } = ne, re = { title: "Shopify" }, le = { manage: { label: "Manage Shopify", description: "Connect a Shopify store, change its sync settings and retry order imports" } }, ce = { title: "Shopify", description: "Import Shopify orders and push stock to your store." }, de = { title: "Shopify", description: "Shopify orders become Inventoros orders, and Inventoros stock is published to Shopify. Products are matched by SKU.", crumb: "Integrations" }, ue = { connected: "Connected to {shop}", not_connected: "Not connected", error: "Last error", acting_user: "Orders and stock changes are recorded as {name}.", missing_scopes: "The Shopify app was not granted {scopes}: publishing products to Shopify, or choosing the warehouse of each order with several locations, will not work until you add them to the app's version and release it." }, pe = { title: "Connection", subtitle: "The Shopify app's credentials, from the Dev Dashboard (App settings).", shop_domain: "Store address", shop_domain_hint: "For example my-store.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Saved. Leave empty to keep it.", api_version: "API version", scopes: "Paste these access scopes into the Scopes field of the app's version in the Shopify Dev Dashboard, then release the version:", save: "Save and test connection", disconnect: "Disconnect", disconnect_confirm: "Disconnect Shopify? Orders already imported stay; the stored credentials are deleted.", scopes_optional: "Optional, for one feature each: {scopes}. The rest works without them." }, he = { title: "Sync settings", import_orders: "Import Shopify orders", import_orders_hint: "New Shopify orders become Inventoros orders and take stock out; cancelling in Shopify cancels them here.", push_inventory: "Push stock to Shopify", push_inventory_hint: "Every stock change in Inventoros sets the available quantity in Shopify. Inventoros is the source of truth.", warehouse: "Default warehouse for Shopify orders", warehouse_default: "Default warehouse", locations: "Shopify locations", locations_hint: "Choose which Inventoros stock each Shopify location shows. Leave a location unsynced to manage its stock in Shopify. With several locations, give each its own warehouse.", location_unsynced: "Not synced", location_all: "All warehouses (total stock)", warehouse_hint: "Used when the order's Shopify location is not mapped to a warehouse.", location_hint: "Save the connection first to load the store's locations." }, _e = { title: "Order webhooks", subtitle: "Shopify notifies Inventoros of new and cancelled orders at this address. It must be public and HTTPS.", register: "Register webhooks in Shopify", registered: "Registered {date}", not_registered: "Not registered yet", copy: "Copy", copied: "Copied", checked: "Checked {date}" }, ye = { title: "Stock sync", subtitle: "Match every Shopify variant to Inventoros by SKU and push all quantities. Run it once after connecting, and whenever SKUs change in bulk.", run: "Sync all stock now", never: "Never run", last_run: "Last run {date}", shopify_skus: "Shopify SKUs", inventoros_skus: "Inventoros SKUs", matched: "Matched", pushed: "Pushed", failed: "Failed", untracked: "Not tracked in Shopify", only_in_shopify: "Only in Shopify", only_in_inventoros: "Only in Inventoros", sample: "First {count}:" }, me = { title: "Recent Shopify orders", empty: "No Shopify orders received yet.", shopify_order: "Shopify order", status: "Status", result: "Result", inventoros_order: "Inventoros order", retry: "Retry", statuses: { imported: "Imported", failed: "Failed", skipped: "Skipped", cancelled: "Cancelled" } }, fe = { title: "Order recovery", subtitle: "Every ten minutes Inventoros reads back the orders Shopify changed, so orders whose webhook was lost are still imported, and edits, fulfilments and refunds still apply. Missing webhooks are registered again.", last_run: "Last run {date}", never: "Not run yet", checked: "Orders checked", imported: "Imported", updated: "Updated", not_imported: "Not imported", ignored: "Older than the import window", webhooks_repaired: "Webhooks registered again: {topics}", from: "Import missing orders created since", from_hint: "Shopify keeps the last 60 days of orders available to apps.", run: "Recover orders" }, ve = { title: "Catalogue", subtitle: "Copy products between Shopify and Inventoros, matched by SKU. Products that already exist on the other side are never changed.", import_title: "Import from Shopify", import_hint: "Creates an Inventoros product for each Shopify product whose SKUs Inventoros does not have, with its options and variants.", with_stock: "Also import Shopify's available stock as opening stock", import: "Import products", publish_title: "Publish to Shopify", publish_hint: "Creates a Shopify product for each active Inventoros product whose SKUs Shopify does not have, with prices, barcodes and stock at the mapped locations.", status_draft: "As drafts", status_active: "As active products", publish: "Publish products", last_run: "Last run {date}", products: "Products", variants: "Variants", existing: "Already in Inventoros", already: "Already in Shopify", partial: "Partly in Shopify", no_sku: "Without SKU", failed: "Failed" }, W = {
  nav: re,
  permissions: le,
  card: ce,
  page: de,
  status: ue,
  connection: pe,
  sync: he,
  webhooks: _e,
  stock: ye,
  orders: me,
  recovery: fe,
  catalogue: ve
}, ge = () => window.Inventoros?.plugin?.("shopify");
function Se(i, b) {
  const k = i.split(".").reduce((m, _) => m && typeof m == "object" ? m[_] : void 0, W);
  return typeof k != "string" ? i : k.replace(/\{(\w+)\}/g, (m, _) => b && _ in b ? String(b[_]) : m);
}
function n(i, b = {}) {
  const k = ge()?.i18n;
  return k?.te?.(i) ? k.t(i, b) : Se(i, b);
}
const G = (i, b) => {
  const k = i.__vccOpts || i;
  for (const [m, _] of b)
    k[m] = _;
  return k;
}, be = { class: "shp-crumbs" }, ke = { class: "shp-page" }, we = {
  key: 0,
  class: "shp-alert",
  role: "alert"
}, Ie = {
  key: 1,
  class: "shp-alert shp-alert-warning",
  role: "status"
}, ze = { class: "shp-fields" }, Ce = { class: "shp-field" }, Ue = { class: "shp-label" }, Re = ["placeholder"], $e = {
  key: 0,
  class: "shp-error"
}, Pe = { class: "shp-field" }, xe = { class: "shp-label" }, De = {
  key: 0,
  class: "shp-error"
}, Te = { class: "shp-field" }, Ve = { class: "shp-label" }, Oe = ["placeholder", "required"], Ae = {
  key: 0,
  class: "shp-hint"
}, Me = {
  key: 1,
  class: "shp-error"
}, Ne = { class: "shp-field" }, Ee = { class: "shp-label" }, Ke = {
  key: 0,
  class: "shp-error"
}, qe = { class: "shp-field" }, Fe = { class: "shp-hint" }, He = { class: "shp-scopes" }, Le = { class: "shp-hint" }, Be = { class: "shp-fields" }, je = { class: "shp-check" }, We = { class: "shp-label" }, Ge = { class: "shp-hint" }, Qe = { class: "shp-check" }, Je = { class: "shp-label" }, Xe = { class: "shp-hint" }, Ye = { class: "shp-field" }, Ze = { class: "shp-label" }, et = { class: "shp-hint" }, tt = {
  key: 0,
  class: "shp-hint"
}, ot = { class: "shp-map-name" }, st = ["onUpdate:modelValue"], nt = { value: "" }, it = { value: "all" }, at = ["value"], rt = {
  key: 1,
  class: "shp-error"
}, lt = { class: "shp-field" }, ct = { class: "shp-label" }, dt = { value: "" }, ut = ["value"], pt = { class: "shp-hint" }, ht = {
  key: 0,
  class: "shp-error"
}, _t = { class: "shp-actions" }, yt = {
  key: 1,
  class: "shp-hint"
}, mt = { class: "shp-url" }, ft = { class: "shp-hint" }, vt = { class: "shp-hint" }, gt = { class: "shp-stats" }, St = {
  key: 0,
  class: "shp-details"
}, bt = { class: "shp-hint" }, kt = { class: "shp-skus" }, wt = { class: "shp-hint" }, It = { class: "shp-stats" }, zt = {
  key: 0,
  class: "shp-hint"
}, Ct = { class: "shp-field" }, Ut = { class: "shp-label" }, Rt = ["min", "max"], $t = { class: "shp-hint" }, Pt = {
  key: 0,
  class: "shp-error"
}, xt = { class: "shp-columns" }, Dt = { class: "shp-subtitle" }, Tt = { class: "shp-hint" }, Vt = { class: "shp-check shp-gap" }, Ot = { class: "shp-label" }, At = { class: "shp-hint shp-gap" }, Mt = { class: "shp-stats shp-stats-compact" }, Nt = {
  key: 0,
  class: "shp-details"
}, Et = { class: "shp-subtitle" }, Kt = { class: "shp-hint" }, qt = { value: "draft" }, Ft = { value: "active" }, Ht = { class: "shp-hint shp-gap" }, Lt = { class: "shp-stats shp-stats-compact" }, Bt = {
  key: 0,
  class: "shp-details"
}, jt = { class: "shp-table-header" }, Wt = {
  key: 0,
  class: "shp-empty"
}, Gt = {
  key: 1,
  class: "shp-table-wrap"
}, Qt = { class: "shp-table" }, Jt = { class: "shp-hint" }, Xt = { class: "shp-message" }, C = "/p/shopify/actions", Yt = {
  __name: "SettingsPage",
  props: {
    title: { type: String, default: "Shopify" },
    connection: { type: Object, default: null },
    webhookUrl: { type: String, default: null },
    defaultApiVersion: { type: String, required: !0 },
    requiredScopes: { type: Array, default: () => [] },
    optionalScopes: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] }
  },
  setup(i) {
    const {
      layouts: { AppLayout: b },
      ui: { PageHeader: k, Card: m, CardHeader: _, Button: I, Badge: K },
      Inertia: { Head: Q, Link: J, router: X, useForm: V }
    } = window.Inventoros, f = i, c = V({
      shop_domain: f.connection?.shop_domain ?? "",
      client_id: f.connection?.client_id ?? "",
      client_secret: "",
      api_version: f.connection?.api_version ?? f.defaultApiVersion,
      warehouse_id: f.connection?.warehouse_id ?? "",
      import_orders: f.connection?.import_orders ?? !0,
      push_inventory: f.connection?.push_inventory ?? !0
    }), R = B(null), q = B(!1), F = ie(Object.fromEntries((f.connection?.locations ?? []).map((g) => {
      const l = (f.connection?.location_map ?? []).find((s) => s.location_id === g.id);
      return [g.id, l ? l.warehouse_id ?? "all" : ""];
    }))), $ = V({ from: new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10) }), O = V({ with_stock: !0 }), A = V({ status: "draft" }), M = E(() => f.connection?.orders_sync_summary ?? null), x = E(() => f.connection?.catalogue_import_summary ?? null), D = E(() => f.connection?.catalogue_publish_summary ?? null), Y = new Date(Date.now() - 59 * 864e5).toISOString().slice(0, 10), Z = (/* @__PURE__ */ new Date()).toISOString().slice(0, 10), z = E(() => f.connection?.last_full_sync_summary ?? null);
    function ee() {
      c.transform((g) => {
        const l = { ...g, warehouse_id: g.warehouse_id || null };
        return f.connection?.locations?.length && (l.location_map = Object.entries(F).filter(([, s]) => s !== "").map(([s, v]) => ({ location_id: s, warehouse_id: v === "all" ? null : v }))), l;
      }).post(`${C}/settings`, {
        preserveScroll: !0,
        onSuccess: () => c.reset("client_secret")
      });
    }
    function N(g, l, s, v = {}) {
      R.value = g, X.visit(s, {
        method: l,
        preserveScroll: !0,
        ...v,
        onFinish: () => {
          R.value = null;
        }
      });
    }
    function te() {
      window.confirm(n("connection.disconnect_confirm")) && N("disconnect", "delete", `${C}/connection`);
    }
    async function oe() {
      try {
        await navigator.clipboard.writeText(f.webhookUrl), q.value = !0, setTimeout(() => q.value = !1, 2e3);
      } catch {
      }
    }
    function U(g) {
      return g ? new Date(g).toLocaleString() : "";
    }
    const se = (g) => ({ imported: "success", failed: "danger", skipped: "warning", cancelled: "neutral" })[g] ?? "neutral";
    return (g, l) => (a(), r(h, null, [
      u(e(Q), { title: i.title }, null, 8, ["title"]),
      u(e(b), null, {
        header: d(() => [
          t("div", be, [
            t("span", null, o(e(n)("page.crumb")), 1),
            l[15] || (l[15] = t("span", null, "/", -1)),
            t("strong", null, o(i.title), 1)
          ])
        ]),
        default: d(() => [
          t("div", ke, [
            u(e(k), {
              title: e(n)("page.title"),
              description: e(n)("page.description")
            }, {
              actions: d(() => [
                i.connection?.connected ? (a(), P(e(K), {
                  key: 0,
                  variant: "success",
                  dot: ""
                }, {
                  default: d(() => [
                    y(o(e(n)("status.connected", { shop: i.connection.shop_name })), 1)
                  ]),
                  _: 1
                })) : (a(), P(e(K), {
                  key: 1,
                  variant: "neutral",
                  dot: ""
                }, {
                  default: d(() => [
                    y(o(e(n)("status.not_connected")), 1)
                  ]),
                  _: 1
                }))
              ]),
              _: 1
            }, 8, ["title", "description"]),
            i.connection?.last_error ? (a(), r("div", we, [
              t("strong", null, o(e(n)("status.error")) + ":", 1),
              y(" " + o(i.connection.last_error), 1)
            ])) : p("", !0),
            i.connection?.missing_scopes?.length ? (a(), r("div", Ie, o(e(n)("status.missing_scopes", { scopes: i.connection.missing_scopes.join(", ") })), 1)) : p("", !0),
            t("form", {
              class: "shp-grid",
              onSubmit: j(ee, ["prevent"])
            }, [
              u(e(m), null, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("connection.title"),
                    subtitle: e(n)("connection.subtitle")
                  }, null, 8, ["title", "subtitle"]),
                  t("div", ze, [
                    t("label", Ce, [
                      t("span", Ue, o(e(n)("connection.shop_domain")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[0] || (l[0] = (s) => e(c).shop_domain = s),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        placeholder: e(n)("connection.shop_domain_hint"),
                        required: ""
                      }, null, 8, Re), [
                        [T, e(c).shop_domain]
                      ]),
                      e(c).errors.shop_domain ? (a(), r("span", $e, o(e(c).errors.shop_domain), 1)) : p("", !0)
                    ]),
                    t("label", Pe, [
                      t("span", xe, o(e(n)("connection.client_id")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[1] || (l[1] = (s) => e(c).client_id = s),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        required: ""
                      }, null, 512), [
                        [T, e(c).client_id]
                      ]),
                      e(c).errors.client_id ? (a(), r("span", De, o(e(c).errors.client_id), 1)) : p("", !0)
                    ]),
                    t("label", Te, [
                      t("span", Ve, o(e(n)("connection.client_secret")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[2] || (l[2] = (s) => e(c).client_secret = s),
                        class: "shp-input",
                        type: "password",
                        autocomplete: "new-password",
                        placeholder: i.connection?.has_client_secret ? "••••••••" : "",
                        required: !i.connection?.has_client_secret
                      }, null, 8, Oe), [
                        [T, e(c).client_secret]
                      ]),
                      i.connection?.has_client_secret ? (a(), r("span", Ae, o(e(n)("connection.client_secret_saved")), 1)) : p("", !0),
                      e(c).errors.client_secret ? (a(), r("span", Me, o(e(c).errors.client_secret), 1)) : p("", !0)
                    ]),
                    t("label", Ne, [
                      t("span", Ee, o(e(n)("connection.api_version")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[3] || (l[3] = (s) => e(c).api_version = s),
                        class: "shp-input shp-input-short",
                        type: "text",
                        pattern: "\\d{4}-\\d{2}"
                      }, null, 512), [
                        [T, e(c).api_version]
                      ]),
                      e(c).errors.api_version ? (a(), r("span", Ke, o(e(c).errors.api_version), 1)) : p("", !0)
                    ]),
                    t("div", qe, [
                      t("span", Fe, o(e(n)("connection.scopes")), 1),
                      t("code", He, o([...i.requiredScopes, ...i.optionalScopes].join(",")), 1),
                      t("span", Le, o(e(n)("connection.scopes_optional", { scopes: i.optionalScopes.join(", ") })), 1)
                    ])
                  ])
                ]),
                _: 1
              }),
              u(e(m), null, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("sync.title")
                  }, null, 8, ["title"]),
                  t("div", Be, [
                    t("label", je, [
                      w(t("input", {
                        "onUpdate:modelValue": l[4] || (l[4] = (s) => e(c).import_orders = s),
                        type: "checkbox"
                      }, null, 512), [
                        [H, e(c).import_orders]
                      ]),
                      t("span", null, [
                        t("span", We, o(e(n)("sync.import_orders")), 1),
                        t("span", Ge, o(e(n)("sync.import_orders_hint")), 1)
                      ])
                    ]),
                    t("label", Qe, [
                      w(t("input", {
                        "onUpdate:modelValue": l[5] || (l[5] = (s) => e(c).push_inventory = s),
                        type: "checkbox"
                      }, null, 512), [
                        [H, e(c).push_inventory]
                      ]),
                      t("span", null, [
                        t("span", Je, o(e(n)("sync.push_inventory")), 1),
                        t("span", Xe, o(e(n)("sync.push_inventory_hint")), 1)
                      ])
                    ]),
                    t("div", Ye, [
                      t("span", Ze, o(e(n)("sync.locations")), 1),
                      t("span", et, o(e(n)("sync.locations_hint")), 1),
                      i.connection?.locations?.length ? p("", !0) : (a(), r("p", tt, o(e(n)("sync.location_hint")), 1)),
                      (a(!0), r(h, null, S(i.connection?.locations ?? [], (s) => (a(), r("div", {
                        key: s.id,
                        class: "shp-map-row"
                      }, [
                        t("span", ot, o(s.name), 1),
                        w(t("select", {
                          "onUpdate:modelValue": (v) => F[s.id] = v,
                          class: "shp-input"
                        }, [
                          t("option", nt, o(e(n)("sync.location_unsynced")), 1),
                          t("option", it, o(e(n)("sync.location_all")), 1),
                          (a(!0), r(h, null, S(i.warehouses, (v) => (a(), r("option", {
                            key: v.id,
                            value: v.id
                          }, o(v.name), 9, at))), 128))
                        ], 8, st), [
                          [L, F[s.id]]
                        ])
                      ]))), 128)),
                      e(c).errors.location_map ? (a(), r("span", rt, o(e(c).errors.location_map), 1)) : p("", !0)
                    ]),
                    t("label", lt, [
                      t("span", ct, o(e(n)("sync.warehouse")), 1),
                      w(t("select", {
                        "onUpdate:modelValue": l[6] || (l[6] = (s) => e(c).warehouse_id = s),
                        class: "shp-input"
                      }, [
                        t("option", dt, o(e(n)("sync.warehouse_default")), 1),
                        (a(!0), r(h, null, S(i.warehouses, (s) => (a(), r("option", {
                          key: s.id,
                          value: s.id
                        }, o(s.name), 9, ut))), 128))
                      ], 512), [
                        [L, e(c).warehouse_id]
                      ]),
                      t("span", pt, o(e(n)("sync.warehouse_hint")), 1),
                      e(c).errors.warehouse_id ? (a(), r("span", ht, o(e(c).errors.warehouse_id), 1)) : p("", !0)
                    ])
                  ])
                ]),
                _: 1
              }),
              t("div", _t, [
                u(e(I), {
                  type: "submit",
                  loading: e(c).processing
                }, {
                  default: d(() => [
                    y(o(e(n)("connection.save")), 1)
                  ]),
                  _: 1
                }, 8, ["loading"]),
                i.connection ? (a(), P(e(I), {
                  key: 0,
                  variant: "ghost",
                  loading: R.value === "disconnect",
                  onClick: te
                }, {
                  default: d(() => [
                    y(o(e(n)("connection.disconnect")), 1)
                  ]),
                  _: 1
                }, 8, ["loading"])) : p("", !0),
                i.connection?.acting_user ? (a(), r("span", yt, o(e(n)("status.acting_user", { name: i.connection.acting_user })), 1)) : p("", !0)
              ])
            ], 32),
            i.connection ? (a(), r(h, { key: 2 }, [
              u(e(m), { class: "shp-section" }, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("webhooks.title"),
                    subtitle: e(n)("webhooks.subtitle")
                  }, {
                    actions: d(() => [
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected,
                        loading: R.value === "webhooks",
                        onClick: l[7] || (l[7] = (s) => N("webhooks", "post", `${C}/webhooks`))
                      }, {
                        default: d(() => [
                          y(o(e(n)("webhooks.register")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("div", mt, [
                    t("code", null, o(i.webhookUrl), 1),
                    u(e(I), {
                      size: "xs",
                      variant: "outline",
                      onClick: oe
                    }, {
                      default: d(() => [
                        y(o(q.value ? e(n)("webhooks.copied") : e(n)("webhooks.copy")), 1)
                      ]),
                      _: 1
                    })
                  ]),
                  t("p", ft, [
                    y(o(i.connection.webhooks_registered_at ? e(n)("webhooks.registered", { date: U(i.connection.webhooks_registered_at) }) : e(n)("webhooks.not_registered")) + " ", 1),
                    i.connection.webhooks_checked_at ? (a(), r(h, { key: 0 }, [
                      y(" · " + o(e(n)("webhooks.checked", { date: U(i.connection.webhooks_checked_at) })), 1)
                    ], 64)) : p("", !0)
                  ])
                ]),
                _: 1
              }),
              u(e(m), { class: "shp-section" }, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("stock.title"),
                    subtitle: e(n)("stock.subtitle")
                  }, {
                    actions: d(() => [
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected || !i.connection.push_inventory || !i.connection.location_map?.length,
                        loading: R.value === "sync",
                        onClick: l[8] || (l[8] = (s) => N("sync", "post", `${C}/sync`))
                      }, {
                        default: d(() => [
                          y(o(e(n)("stock.run")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("p", vt, o(i.connection.last_full_sync_at ? e(n)("stock.last_run", { date: U(i.connection.last_full_sync_at) }) : e(n)("stock.never")), 1),
                  z.value ? (a(), r(h, { key: 0 }, [
                    t("dl", gt, [
                      (a(), r(h, null, S(["shopify_skus", "inventoros_skus", "matched", "pushed", "failed", "untracked", "only_in_shopify", "only_in_inventoros"], (s) => t("div", { key: s }, [
                        t("dt", null, o(e(n)(`stock.${s}`)), 1),
                        t("dd", null, o(z.value[s] ?? 0), 1)
                      ])), 64))
                    ]),
                    z.value.failed_sample?.length ? (a(), r("details", St, [
                      t("summary", null, o(e(n)("stock.failed")) + " (" + o(z.value.failed) + ")", 1),
                      t("ul", null, [
                        (a(!0), r(h, null, S(z.value.failed_sample, (s) => (a(), r("li", {
                          key: s.sku
                        }, [
                          t("code", null, o(s.sku), 1),
                          y(": " + o(s.error), 1)
                        ]))), 128))
                      ])
                    ])) : p("", !0),
                    (a(), r(h, null, S(["untracked", "only_in_shopify", "only_in_inventoros"], (s) => w(t("details", {
                      key: s,
                      class: "shp-details"
                    }, [
                      t("summary", null, o(e(n)(`stock.${s}`)) + " (" + o(z.value[s]) + ")", 1),
                      t("p", bt, o(e(n)("stock.sample", { count: z.value[`${s}_sample`]?.length ?? 0 })), 1),
                      t("p", kt, [
                        (a(!0), r(h, null, S(z.value[`${s}_sample`] ?? [], (v) => (a(), r("code", { key: v }, o(v), 1))), 128))
                      ])
                    ], 512), [
                      [ae, z.value[`${s}_sample`]?.length]
                    ])), 64))
                  ], 64)) : p("", !0)
                ]),
                _: 1
              }),
              u(e(m), { class: "shp-section" }, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("recovery.title"),
                    subtitle: e(n)("recovery.subtitle")
                  }, null, 8, ["title", "subtitle"]),
                  t("p", wt, o(i.connection.orders_synced_at ? e(n)("recovery.last_run", { date: U(i.connection.orders_synced_at) }) : e(n)("recovery.never")), 1),
                  M.value ? (a(), r(h, { key: 0 }, [
                    t("dl", It, [
                      (a(), r(h, null, S(["checked", "imported", "updated", "not_imported"], (s) => t("div", { key: s }, [
                        t("dt", null, o(e(n)(`recovery.${s}`)), 1),
                        t("dd", null, o(M.value[s] ?? 0), 1)
                      ])), 64))
                    ]),
                    M.value.webhooks_repaired?.length ? (a(), r("p", zt, o(e(n)("recovery.webhooks_repaired", { topics: M.value.webhooks_repaired.join(", ") })), 1)) : p("", !0)
                  ], 64)) : p("", !0),
                  t("form", {
                    class: "shp-inline",
                    onSubmit: l[10] || (l[10] = j((s) => e($).post(`${C}/recover`, { preserveScroll: !0 }), ["prevent"]))
                  }, [
                    t("label", Ct, [
                      t("span", Ut, o(e(n)("recovery.from")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[9] || (l[9] = (s) => e($).from = s),
                        class: "shp-input shp-input-short",
                        type: "date",
                        min: e(Y),
                        max: e(Z),
                        required: ""
                      }, null, 8, Rt), [
                        [T, e($).from]
                      ]),
                      t("span", $t, o(e(n)("recovery.from_hint")), 1),
                      e($).errors.from ? (a(), r("span", Pt, o(e($).errors.from), 1)) : p("", !0)
                    ]),
                    u(e(I), {
                      type: "submit",
                      size: "sm",
                      disabled: !i.connection.connected || !i.connection.import_orders,
                      loading: e($).processing
                    }, {
                      default: d(() => [
                        y(o(e(n)("recovery.run")), 1)
                      ]),
                      _: 1
                    }, 8, ["disabled", "loading"])
                  ], 32)
                ]),
                _: 1
              }),
              u(e(m), { class: "shp-section" }, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("catalogue.title"),
                    subtitle: e(n)("catalogue.subtitle")
                  }, null, 8, ["title", "subtitle"]),
                  t("div", xt, [
                    t("div", null, [
                      t("h4", Dt, o(e(n)("catalogue.import_title")), 1),
                      t("p", Tt, o(e(n)("catalogue.import_hint")), 1),
                      t("label", Vt, [
                        w(t("input", {
                          "onUpdate:modelValue": l[11] || (l[11] = (s) => e(O).with_stock = s),
                          type: "checkbox"
                        }, null, 512), [
                          [H, e(O).with_stock]
                        ]),
                        t("span", Ot, o(e(n)("catalogue.with_stock")), 1)
                      ]),
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected,
                        loading: e(O).processing,
                        onClick: l[12] || (l[12] = (s) => e(O).post(`${C}/catalogue/import`, { preserveScroll: !0 }))
                      }, {
                        default: d(() => [
                          y(o(e(n)("catalogue.import")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"]),
                      x.value ? (a(), r(h, { key: 0 }, [
                        t("p", At, o(e(n)("catalogue.last_run", { date: U(x.value.finished_at) })), 1),
                        t("dl", Mt, [
                          (a(), r(h, null, S(["products", "variants", "existing", "no_sku", "failed"], (s) => t("div", { key: s }, [
                            t("dt", null, o(e(n)(`catalogue.${s}`)), 1),
                            t("dd", null, o(x.value[s] ?? 0), 1)
                          ])), 64))
                        ]),
                        x.value.failed_sample?.length ? (a(), r("details", Nt, [
                          t("summary", null, o(e(n)("catalogue.failed")), 1),
                          t("ul", null, [
                            (a(!0), r(h, null, S(x.value.failed_sample, (s) => (a(), r("li", { key: s }, o(s), 1))), 128))
                          ])
                        ])) : p("", !0)
                      ], 64)) : p("", !0)
                    ]),
                    t("div", null, [
                      t("h4", Et, o(e(n)("catalogue.publish_title")), 1),
                      t("p", Kt, o(e(n)("catalogue.publish_hint")), 1),
                      w(t("select", {
                        "onUpdate:modelValue": l[13] || (l[13] = (s) => e(A).status = s),
                        class: "shp-input shp-input-short shp-gap"
                      }, [
                        t("option", qt, o(e(n)("catalogue.status_draft")), 1),
                        t("option", Ft, o(e(n)("catalogue.status_active")), 1)
                      ], 512), [
                        [L, e(A).status]
                      ]),
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected || !i.connection.location_map?.length,
                        loading: e(A).processing,
                        onClick: l[14] || (l[14] = (s) => e(A).post(`${C}/catalogue/publish`, { preserveScroll: !0 }))
                      }, {
                        default: d(() => [
                          y(o(e(n)("catalogue.publish")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"]),
                      D.value ? (a(), r(h, { key: 0 }, [
                        t("p", Ht, o(e(n)("catalogue.last_run", { date: U(D.value.finished_at) })), 1),
                        t("dl", Lt, [
                          (a(), r(h, null, S(["products", "variants", "already", "partial", "no_sku", "failed"], (s) => t("div", { key: s }, [
                            t("dt", null, o(e(n)(`catalogue.${s}`)), 1),
                            t("dd", null, o(D.value[s] ?? 0), 1)
                          ])), 64))
                        ]),
                        D.value.failed_sample?.length ? (a(), r("details", Bt, [
                          t("summary", null, o(e(n)("catalogue.failed")), 1),
                          t("ul", null, [
                            (a(!0), r(h, null, S(D.value.failed_sample, (s) => (a(), r("li", { key: s }, o(s), 1))), 128))
                          ])
                        ])) : p("", !0)
                      ], 64)) : p("", !0)
                    ])
                  ])
                ]),
                _: 1
              }),
              u(e(m), {
                class: "shp-section",
                padded: !1
              }, {
                default: d(() => [
                  t("div", jt, [
                    u(e(_), {
                      title: e(n)("orders.title")
                    }, null, 8, ["title"])
                  ]),
                  i.orders.length ? (a(), r("div", Gt, [
                    t("table", Qt, [
                      t("thead", null, [
                        t("tr", null, [
                          t("th", null, o(e(n)("orders.shopify_order")), 1),
                          t("th", null, o(e(n)("orders.status")), 1),
                          t("th", null, o(e(n)("orders.result")), 1),
                          t("th", null, o(e(n)("orders.inventoros_order")), 1),
                          l[16] || (l[16] = t("th", null, null, -1))
                        ])
                      ]),
                      t("tbody", null, [
                        (a(!0), r(h, null, S(i.orders, (s) => (a(), r("tr", {
                          key: s.id
                        }, [
                          t("td", null, [
                            t("strong", null, o(s.name), 1),
                            t("span", Jt, o(U(s.updated_at)), 1)
                          ]),
                          t("td", null, [
                            u(e(K), {
                              variant: se(s.status),
                              size: "sm"
                            }, {
                              default: d(() => [
                                y(o(e(n)(`orders.statuses.${s.status}`)), 1)
                              ]),
                              _: 2
                            }, 1032, ["variant"])
                          ]),
                          t("td", Xt, o(s.message), 1),
                          t("td", null, [
                            s.order_url ? (a(), P(e(J), {
                              key: 0,
                              href: s.order_url
                            }, {
                              default: d(() => [
                                y(o(s.order_number), 1)
                              ]),
                              _: 2
                            }, 1032, ["href"])) : p("", !0)
                          ]),
                          t("td", null, [
                            s.retryable ? (a(), P(e(I), {
                              key: 0,
                              size: "xs",
                              variant: "outline",
                              loading: R.value === `retry-${s.id}`,
                              onClick: (v) => N(`retry-${s.id}`, "post", `${C}/orders/${s.id}/retry`)
                            }, {
                              default: d(() => [
                                y(o(e(n)("orders.retry")), 1)
                              ]),
                              _: 1
                            }, 8, ["loading", "onClick"])) : p("", !0)
                          ])
                        ]))), 128))
                      ])
                    ])
                  ])) : (a(), r("p", Wt, o(e(n)("orders.empty")), 1))
                ]),
                _: 1
              })
            ], 64)) : p("", !0)
          ])
        ]),
        _: 1
      })
    ], 64));
  }
}, Zt = /* @__PURE__ */ G(Yt, [["__scopeId", "data-v-c8de54e3"]]), eo = { class: "shp-card" }, to = { class: "shp-card-body" }, oo = { class: "shp-card-title" }, so = { class: "shp-card-text" }, no = {
  __name: "ShopifySettingsCard",
  props: {
    url: { type: String, required: !0 }
  },
  setup(i) {
    const {
      ui: { Card: b },
      Inertia: { Link: k }
    } = window.Inventoros;
    return (m, _) => (a(), P(e(k), { href: i.url }, {
      default: d(() => [
        u(e(b), { hoverable: "" }, {
          default: d(() => [
            t("div", eo, [
              _[0] || (_[0] = t("span", {
                class: "shp-card-icon",
                "aria-hidden": "true"
              }, "S", -1)),
              t("div", to, [
                t("h3", oo, o(e(n)("card.title")), 1),
                t("p", so, o(e(n)("card.description")), 1)
              ])
            ])
          ]),
          _: 1
        })
      ]),
      _: 1
    }, 8, ["href"]));
  }
}, io = /* @__PURE__ */ G(no, [["__scopeId", "data-v-0691ef2f"]]), ao = { title: "Shopify" }, ro = { manage: { label: "Gestire Shopify", description: "Collegare un negozio Shopify, modificarne le impostazioni di sincronizzazione e ritentare l'importazione degli ordini" } }, lo = { title: "Shopify", description: "Importa gli ordini Shopify e pubblica le giacenze nel negozio." }, co = { title: "Shopify", description: "Gli ordini Shopify diventano ordini Inventoros e le giacenze di Inventoros vengono pubblicate su Shopify. I prodotti sono abbinati per SKU.", crumb: "Integrazioni" }, uo = { connected: "Collegato a {shop}", not_connected: "Non collegato", error: "Ultimo errore", acting_user: "Ordini e movimenti di stock sono registrati come {name}.", missing_scopes: "All'app Shopify non sono stati concessi {scopes}: la pubblicazione dei prodotti su Shopify, o la scelta del magazzino di ogni ordine con più sedi, non funzionerà finché non li aggiungi alla versione dell'app e la rilasci." }, po = { title: "Connessione", subtitle: "Le credenziali dell'app Shopify, dal Dev Dashboard (App settings).", shop_domain: "Indirizzo del negozio", shop_domain_hint: "Per esempio mio-negozio.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Salvato. Lascia vuoto per mantenerlo.", api_version: "Versione API", scopes: "Incolla questi permessi (access scopes) nel campo Scopes della versione dell'app nel Dev Dashboard di Shopify, poi rilascia la versione:", save: "Salva e prova la connessione", disconnect: "Scollega", disconnect_confirm: "Scollegare Shopify? Gli ordini già importati restano; le credenziali salvate vengono eliminate.", scopes_optional: "Facoltativi, ciascuno per una funzione: {scopes}. Il resto funziona anche senza." }, ho = { title: "Impostazioni di sincronizzazione", import_orders: "Importa gli ordini Shopify", import_orders_hint: "I nuovi ordini Shopify diventano ordini Inventoros e scalano lo stock; annullarli in Shopify li annulla anche qui.", push_inventory: "Pubblica le giacenze su Shopify", push_inventory_hint: "Ogni movimento di stock in Inventoros imposta la quantità disponibile su Shopify. Inventoros è la fonte di verità.", warehouse: "Magazzino predefinito per gli ordini Shopify", warehouse_default: "Magazzino predefinito", locations: "Sedi Shopify", locations_hint: "Scegli quale stock di Inventoros mostra ogni sede Shopify. Lascia una sede non sincronizzata per gestirne lo stock su Shopify. Con più sedi, assegna a ciascuna il proprio magazzino.", location_unsynced: "Non sincronizzata", location_all: "Tutti i magazzini (stock totale)", warehouse_hint: "Usato quando la sede Shopify dell'ordine non è collegata a un magazzino.", location_hint: "Salva prima la connessione per caricare le sedi del negozio." }, _o = { title: "Webhook degli ordini", subtitle: "Shopify avvisa Inventoros dei nuovi ordini e di quelli annullati a questo indirizzo. Deve essere pubblico e HTTPS.", register: "Registra i webhook su Shopify", registered: "Registrati il {date}", not_registered: "Non ancora registrati", copy: "Copia", copied: "Copiato", checked: "Controllati il {date}" }, yo = { title: "Sincronizzazione giacenze", subtitle: "Abbina ogni variante Shopify a Inventoros tramite SKU e pubblica tutte le quantità. Eseguila una volta dopo il collegamento e ogni volta che cambi molti SKU.", run: "Sincronizza tutte le giacenze", never: "Mai eseguita", last_run: "Ultima esecuzione {date}", shopify_skus: "SKU su Shopify", inventoros_skus: "SKU su Inventoros", matched: "Abbinati", pushed: "Pubblicati", failed: "Falliti", untracked: "Non tracciati su Shopify", only_in_shopify: "Solo su Shopify", only_in_inventoros: "Solo su Inventoros", sample: "Primi {count}:" }, mo = { title: "Ordini Shopify recenti", empty: "Nessun ordine Shopify ricevuto finora.", shopify_order: "Ordine Shopify", status: "Stato", result: "Esito", inventoros_order: "Ordine Inventoros", retry: "Riprova", statuses: { imported: "Importato", failed: "Fallito", skipped: "Saltato", cancelled: "Annullato" } }, fo = { title: "Recupero ordini", subtitle: "Ogni dieci minuti Inventoros rilegge gli ordini modificati su Shopify: gli ordini il cui webhook è andato perso vengono comunque importati, e modifiche, evasioni e rimborsi vengono applicati. I webhook mancanti vengono registrati di nuovo.", last_run: "Ultima esecuzione {date}", never: "Non ancora eseguito", checked: "Ordini controllati", imported: "Importati", updated: "Aggiornati", not_imported: "Non importati", ignored: "Precedenti alla finestra di importazione", webhooks_repaired: "Webhook registrati di nuovo: {topics}", from: "Importa gli ordini mancanti creati dal", from_hint: "Shopify rende disponibili alle app gli ordini degli ultimi 60 giorni.", run: "Recupera ordini" }, vo = { title: "Catalogo", subtitle: "Copia i prodotti tra Shopify e Inventoros, abbinati per SKU. I prodotti che esistono già dall'altra parte non vengono mai modificati.", import_title: "Importa da Shopify", import_hint: "Crea un prodotto Inventoros per ogni prodotto Shopify i cui SKU non esistono in Inventoros, con opzioni e varianti.", with_stock: "Importa anche lo stock disponibile su Shopify come giacenza iniziale", import: "Importa prodotti", publish_title: "Pubblica su Shopify", publish_hint: "Crea un prodotto Shopify per ogni prodotto Inventoros attivo i cui SKU non esistono su Shopify, con prezzi, barcode e stock nelle sedi collegate.", status_draft: "Come bozze", status_active: "Come prodotti attivi", publish: "Pubblica prodotti", last_run: "Ultima esecuzione {date}", products: "Prodotti", variants: "Varianti", existing: "Già in Inventoros", already: "Già su Shopify", partial: "In parte su Shopify", no_sku: "Senza SKU", failed: "Falliti" }, go = {
  nav: ao,
  permissions: ro,
  card: lo,
  page: co,
  status: uo,
  connection: po,
  sync: ho,
  webhooks: _o,
  stock: yo,
  orders: mo,
  recovery: fo,
  catalogue: vo
};
function di(i) {
  i.i18n?.addMessages("en", W), i.i18n?.addMessages("it", go), i.registerPage("Settings", Zt), i.registerComponent("ShopifySettingsCard", io);
}
export {
  di as default
};
