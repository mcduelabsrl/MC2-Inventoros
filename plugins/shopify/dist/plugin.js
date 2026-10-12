const ne = window.Inventoros.Vue, { BaseTransition: mo, BaseTransitionPropsValidators: fo, Comment: vo, DeprecationTypes: go, EffectScope: So, ErrorCodes: bo, ErrorTypeStrings: ko, Fragment: h, KeepAlive: wo, ReactiveEffect: Io, Static: Co, Suspense: zo, Teleport: Uo, Text: Ro, TrackOpTypes: $o, Transition: Po, TransitionGroup: xo, TriggerOpTypes: Vo, VueElement: To, __esModule: Do, assertNumber: Oo, callWithAsyncErrorHandling: Ao, callWithErrorHandling: Mo, camelize: No, capitalize: Eo, cloneVNode: Ko, compatUtils: qo, compile: Fo, computed: E, createApp: Lo, createBlock: P, createCommentVNode: p, createElementBlock: r, createElementVNode: t, createHydrationRenderer: Ho, createPropsRestProxy: Bo, createRenderer: Wo, createSSRApp: jo, createSlots: Go, createStaticVNode: Qo, createTextVNode: y, createVNode: u, customRef: Jo, defineAsyncComponent: Xo, defineComponent: Yo, defineCustomElement: Zo, defineEmits: es, defineExpose: ts, defineModel: os, defineOptions: ss, defineProps: ns, defineSSRCustomElement: is, defineSlots: as, devtools: rs, effect: ls, effectScope: cs, getCurrentInstance: ds, getCurrentScope: us, getCurrentWatcher: ps, getTransitionRawChildren: hs, guardReactiveProps: _s, h: ys, handleError: ms, hasInjectionContext: fs, hydrate: vs, hydrateOnIdle: gs, hydrateOnInteraction: Ss, hydrateOnMediaQuery: bs, hydrateOnVisible: ks, initCustomFormatter: ws, initDirectivesForSSR: Is, inject: Cs, isMemoSame: zs, isProxy: Us, isReactive: Rs, isReadonly: $s, isRef: Ps, isRuntimeOnly: xs, isShallow: Vs, isVNode: Ts, markRaw: Ds, mergeDefaults: Os, mergeModels: As, mergeProps: Ms, nextTick: Ns, nodeOps: Es, normalizeClass: Ks, normalizeProps: qs, normalizeStyle: Fs, onActivated: Ls, onBeforeMount: Hs, onBeforeUnmount: Bs, onBeforeUpdate: Ws, onDeactivated: js, onErrorCaptured: Gs, onMounted: Qs, onRenderTracked: Js, onRenderTriggered: Xs, onScopeDispose: Ys, onServerPrefetch: Zs, onUnmounted: en, onUpdated: tn, onWatcherCleanup: on, openBlock: a, patchProp: sn, popScopeId: nn, provide: an, proxyRefs: rn, pushScopeId: ln, queuePostFlushCb: cn, reactive: ie, readonly: dn, ref: B, registerRuntimeCompiler: un, render: pn, renderList: S, renderSlot: hn, resolveComponent: _n, resolveDirective: yn, resolveDynamicComponent: mn, resolveFilter: fn, resolveTransitionHooks: vn, setBlockTracking: gn, setDevtoolsHook: Sn, setTransitionHooks: bn, shallowReactive: kn, shallowReadonly: wn, shallowRef: In, ssrContextKey: Cn, ssrUtils: zn, stop: Un, toDisplayString: o, toHandlerKey: Rn, toHandlers: $n, toRaw: Pn, toRef: xn, toRefs: Vn, toValue: Tn, transformVNodeArgs: Dn, triggerRef: On, unref: e, useAttrs: An, useCssModule: Mn, useCssVars: Nn, useHost: En, useId: Kn, useModel: qn, useSSRContext: Fn, useShadowRoot: Ln, useSlots: Hn, useTemplateRef: Bn, useTransitionState: Wn, vModelCheckbox: L, vModelDynamic: jn, vModelRadio: Gn, vModelSelect: H, vModelText: T, vShow: ae, version: Qn, warn: Jn, watch: Xn, watchEffect: Yn, watchPostEffect: Zn, watchSyncEffect: ei, withAsyncContext: ti, withCtx: d, withDefaults: oi, withDirectives: w, withKeys: si, withMemo: ni, withModifiers: W, withScopeId: ii } = ne, re = { title: "Shopify" }, le = { manage: { label: "Manage Shopify", description: "Connect a Shopify store, change its sync settings and retry order imports" } }, ce = { title: "Shopify", description: "Import Shopify orders and push stock to your store." }, de = { title: "Shopify", description: "Shopify orders become Inventoros orders, and Inventoros stock is published to Shopify. Products are matched by SKU.", crumb: "Integrations" }, ue = { connected: "Connected to {shop}", not_connected: "Not connected", error: "Last error", acting_user: "Orders and stock changes are recorded as {name}." }, pe = { title: "Connection", subtitle: "The Shopify app's credentials, from the Dev Dashboard (App settings).", shop_domain: "Store address", shop_domain_hint: "For example my-store.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Saved. Leave empty to keep it.", api_version: "API version", scopes: "The app needs these scopes: {scopes}.", save: "Save and test connection", disconnect: "Disconnect", disconnect_confirm: "Disconnect Shopify? Orders already imported stay; the stored credentials are deleted." }, he = { title: "Sync settings", import_orders: "Import Shopify orders", import_orders_hint: "New Shopify orders become Inventoros orders and take stock out; cancelling in Shopify cancels them here.", push_inventory: "Push stock to Shopify", push_inventory_hint: "Every stock change in Inventoros sets the available quantity in Shopify. Inventoros is the source of truth.", warehouse: "Default warehouse for Shopify orders", warehouse_default: "Default warehouse", locations: "Shopify locations", locations_hint: "Choose which Inventoros stock each Shopify location shows. Leave a location unsynced to manage its stock in Shopify. With several locations, give each its own warehouse.", location_unsynced: "Not synced", location_all: "All warehouses (total stock)", warehouse_hint: "Used when the order's Shopify location is not mapped to a warehouse.", location_hint: "Save the connection first to load the store's locations." }, _e = { title: "Order webhooks", subtitle: "Shopify notifies Inventoros of new and cancelled orders at this address. It must be public and HTTPS.", register: "Register webhooks in Shopify", registered: "Registered {date}", not_registered: "Not registered yet", copy: "Copy", copied: "Copied", checked: "Checked {date}" }, ye = { title: "Stock sync", subtitle: "Match every Shopify variant to Inventoros by SKU and push all quantities. Run it once after connecting, and whenever SKUs change in bulk.", run: "Sync all stock now", never: "Never run", last_run: "Last run {date}", shopify_skus: "Shopify SKUs", inventoros_skus: "Inventoros SKUs", matched: "Matched", pushed: "Pushed", failed: "Failed", untracked: "Not tracked in Shopify", only_in_shopify: "Only in Shopify", only_in_inventoros: "Only in Inventoros", sample: "First {count}:" }, me = { title: "Recent Shopify orders", empty: "No Shopify orders received yet.", shopify_order: "Shopify order", status: "Status", result: "Result", inventoros_order: "Inventoros order", retry: "Retry", statuses: { imported: "Imported", failed: "Failed", skipped: "Skipped", cancelled: "Cancelled" } }, fe = { title: "Order recovery", subtitle: "Every ten minutes Inventoros reads back the orders Shopify changed, so orders whose webhook was lost are still imported, and edits, fulfilments and refunds still apply. Missing webhooks are registered again.", last_run: "Last run {date}", never: "Not run yet", checked: "Orders checked", imported: "Imported", updated: "Updated", not_imported: "Not imported", ignored: "Older than the import window", webhooks_repaired: "Webhooks registered again: {topics}", from: "Import missing orders created since", from_hint: "Shopify keeps the last 60 days of orders available to apps.", run: "Recover orders" }, ve = { title: "Catalogue", subtitle: "Copy products between Shopify and Inventoros, matched by SKU. Products that already exist on the other side are never changed.", import_title: "Import from Shopify", import_hint: "Creates an Inventoros product for each Shopify product whose SKUs Inventoros does not have, with its options and variants.", with_stock: "Also import Shopify's available stock as opening stock", import: "Import products", publish_title: "Publish to Shopify", publish_hint: "Creates a Shopify product for each active Inventoros product whose SKUs Shopify does not have, with prices, barcodes and stock at the mapped locations.", status_draft: "As drafts", status_active: "As active products", publish: "Publish products", last_run: "Last run {date}", products: "Products", variants: "Variants", existing: "Already in Inventoros", already: "Already in Shopify", partial: "Partly in Shopify", no_sku: "Without SKU", failed: "Failed" }, j = {
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
  const k = i.split(".").reduce((m, _) => m && typeof m == "object" ? m[_] : void 0, j);
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
}, Ie = { class: "shp-fields" }, Ce = { class: "shp-field" }, ze = { class: "shp-label" }, Ue = ["placeholder"], Re = {
  key: 0,
  class: "shp-error"
}, $e = { class: "shp-field" }, Pe = { class: "shp-label" }, xe = {
  key: 0,
  class: "shp-error"
}, Ve = { class: "shp-field" }, Te = { class: "shp-label" }, De = ["placeholder", "required"], Oe = {
  key: 0,
  class: "shp-hint"
}, Ae = {
  key: 1,
  class: "shp-error"
}, Me = { class: "shp-field" }, Ne = { class: "shp-label" }, Ee = {
  key: 0,
  class: "shp-error"
}, Ke = { class: "shp-hint" }, qe = { class: "shp-fields" }, Fe = { class: "shp-check" }, Le = { class: "shp-label" }, He = { class: "shp-hint" }, Be = { class: "shp-check" }, We = { class: "shp-label" }, je = { class: "shp-hint" }, Ge = { class: "shp-field" }, Qe = { class: "shp-label" }, Je = { class: "shp-hint" }, Xe = {
  key: 0,
  class: "shp-hint"
}, Ye = { class: "shp-map-name" }, Ze = ["onUpdate:modelValue"], et = { value: "" }, tt = { value: "all" }, ot = ["value"], st = {
  key: 1,
  class: "shp-error"
}, nt = { class: "shp-field" }, it = { class: "shp-label" }, at = { value: "" }, rt = ["value"], lt = { class: "shp-hint" }, ct = {
  key: 0,
  class: "shp-error"
}, dt = { class: "shp-actions" }, ut = {
  key: 1,
  class: "shp-hint"
}, pt = { class: "shp-url" }, ht = { class: "shp-hint" }, _t = { class: "shp-hint" }, yt = { class: "shp-stats" }, mt = {
  key: 0,
  class: "shp-details"
}, ft = { class: "shp-hint" }, vt = { class: "shp-skus" }, gt = { class: "shp-hint" }, St = { class: "shp-stats" }, bt = {
  key: 0,
  class: "shp-hint"
}, kt = { class: "shp-field" }, wt = { class: "shp-label" }, It = ["min", "max"], Ct = { class: "shp-hint" }, zt = {
  key: 0,
  class: "shp-error"
}, Ut = { class: "shp-columns" }, Rt = { class: "shp-subtitle" }, $t = { class: "shp-hint" }, Pt = { class: "shp-check shp-gap" }, xt = { class: "shp-label" }, Vt = { class: "shp-hint shp-gap" }, Tt = { class: "shp-stats shp-stats-compact" }, Dt = {
  key: 0,
  class: "shp-details"
}, Ot = { class: "shp-subtitle" }, At = { class: "shp-hint" }, Mt = { value: "draft" }, Nt = { value: "active" }, Et = { class: "shp-hint shp-gap" }, Kt = { class: "shp-stats shp-stats-compact" }, qt = {
  key: 0,
  class: "shp-details"
}, Ft = { class: "shp-table-header" }, Lt = {
  key: 0,
  class: "shp-empty"
}, Ht = {
  key: 1,
  class: "shp-table-wrap"
}, Bt = { class: "shp-table" }, Wt = { class: "shp-hint" }, jt = { class: "shp-message" }, z = "/p/shopify/actions", Gt = {
  __name: "SettingsPage",
  props: {
    title: { type: String, default: "Shopify" },
    connection: { type: Object, default: null },
    webhookUrl: { type: String, default: null },
    defaultApiVersion: { type: String, required: !0 },
    requiredScopes: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] }
  },
  setup(i) {
    const {
      layouts: { AppLayout: b },
      ui: { PageHeader: k, Card: m, CardHeader: _, Button: I, Badge: K },
      Inertia: { Head: Q, Link: J, router: X, useForm: D }
    } = window.Inventoros, f = i, c = D({
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
    }))), $ = D({ from: new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10) }), O = D({ with_stock: !0 }), A = D({ status: "draft" }), M = E(() => f.connection?.orders_sync_summary ?? null), x = E(() => f.connection?.catalogue_import_summary ?? null), V = E(() => f.connection?.catalogue_publish_summary ?? null), Y = new Date(Date.now() - 59 * 864e5).toISOString().slice(0, 10), Z = (/* @__PURE__ */ new Date()).toISOString().slice(0, 10), C = E(() => f.connection?.last_full_sync_summary ?? null);
    function ee() {
      c.transform((g) => {
        const l = { ...g, warehouse_id: g.warehouse_id || null };
        return f.connection?.locations?.length && (l.location_map = Object.entries(F).filter(([, s]) => s !== "").map(([s, v]) => ({ location_id: s, warehouse_id: v === "all" ? null : v }))), l;
      }).post(`${z}/settings`, {
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
      window.confirm(n("connection.disconnect_confirm")) && N("disconnect", "delete", `${z}/connection`);
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
            t("form", {
              class: "shp-grid",
              onSubmit: W(ee, ["prevent"])
            }, [
              u(e(m), null, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("connection.title"),
                    subtitle: e(n)("connection.subtitle")
                  }, null, 8, ["title", "subtitle"]),
                  t("div", Ie, [
                    t("label", Ce, [
                      t("span", ze, o(e(n)("connection.shop_domain")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[0] || (l[0] = (s) => e(c).shop_domain = s),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        placeholder: e(n)("connection.shop_domain_hint"),
                        required: ""
                      }, null, 8, Ue), [
                        [T, e(c).shop_domain]
                      ]),
                      e(c).errors.shop_domain ? (a(), r("span", Re, o(e(c).errors.shop_domain), 1)) : p("", !0)
                    ]),
                    t("label", $e, [
                      t("span", Pe, o(e(n)("connection.client_id")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[1] || (l[1] = (s) => e(c).client_id = s),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        required: ""
                      }, null, 512), [
                        [T, e(c).client_id]
                      ]),
                      e(c).errors.client_id ? (a(), r("span", xe, o(e(c).errors.client_id), 1)) : p("", !0)
                    ]),
                    t("label", Ve, [
                      t("span", Te, o(e(n)("connection.client_secret")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[2] || (l[2] = (s) => e(c).client_secret = s),
                        class: "shp-input",
                        type: "password",
                        autocomplete: "new-password",
                        placeholder: i.connection?.has_client_secret ? "••••••••" : "",
                        required: !i.connection?.has_client_secret
                      }, null, 8, De), [
                        [T, e(c).client_secret]
                      ]),
                      i.connection?.has_client_secret ? (a(), r("span", Oe, o(e(n)("connection.client_secret_saved")), 1)) : p("", !0),
                      e(c).errors.client_secret ? (a(), r("span", Ae, o(e(c).errors.client_secret), 1)) : p("", !0)
                    ]),
                    t("label", Me, [
                      t("span", Ne, o(e(n)("connection.api_version")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[3] || (l[3] = (s) => e(c).api_version = s),
                        class: "shp-input shp-input-short",
                        type: "text",
                        pattern: "\\d{4}-\\d{2}"
                      }, null, 512), [
                        [T, e(c).api_version]
                      ]),
                      e(c).errors.api_version ? (a(), r("span", Ee, o(e(c).errors.api_version), 1)) : p("", !0)
                    ]),
                    t("p", Ke, o(e(n)("connection.scopes", { scopes: i.requiredScopes.join(", ") })), 1)
                  ])
                ]),
                _: 1
              }),
              u(e(m), null, {
                default: d(() => [
                  u(e(_), {
                    title: e(n)("sync.title")
                  }, null, 8, ["title"]),
                  t("div", qe, [
                    t("label", Fe, [
                      w(t("input", {
                        "onUpdate:modelValue": l[4] || (l[4] = (s) => e(c).import_orders = s),
                        type: "checkbox"
                      }, null, 512), [
                        [L, e(c).import_orders]
                      ]),
                      t("span", null, [
                        t("span", Le, o(e(n)("sync.import_orders")), 1),
                        t("span", He, o(e(n)("sync.import_orders_hint")), 1)
                      ])
                    ]),
                    t("label", Be, [
                      w(t("input", {
                        "onUpdate:modelValue": l[5] || (l[5] = (s) => e(c).push_inventory = s),
                        type: "checkbox"
                      }, null, 512), [
                        [L, e(c).push_inventory]
                      ]),
                      t("span", null, [
                        t("span", We, o(e(n)("sync.push_inventory")), 1),
                        t("span", je, o(e(n)("sync.push_inventory_hint")), 1)
                      ])
                    ]),
                    t("div", Ge, [
                      t("span", Qe, o(e(n)("sync.locations")), 1),
                      t("span", Je, o(e(n)("sync.locations_hint")), 1),
                      i.connection?.locations?.length ? p("", !0) : (a(), r("p", Xe, o(e(n)("sync.location_hint")), 1)),
                      (a(!0), r(h, null, S(i.connection?.locations ?? [], (s) => (a(), r("div", {
                        key: s.id,
                        class: "shp-map-row"
                      }, [
                        t("span", Ye, o(s.name), 1),
                        w(t("select", {
                          "onUpdate:modelValue": (v) => F[s.id] = v,
                          class: "shp-input"
                        }, [
                          t("option", et, o(e(n)("sync.location_unsynced")), 1),
                          t("option", tt, o(e(n)("sync.location_all")), 1),
                          (a(!0), r(h, null, S(i.warehouses, (v) => (a(), r("option", {
                            key: v.id,
                            value: v.id
                          }, o(v.name), 9, ot))), 128))
                        ], 8, Ze), [
                          [H, F[s.id]]
                        ])
                      ]))), 128)),
                      e(c).errors.location_map ? (a(), r("span", st, o(e(c).errors.location_map), 1)) : p("", !0)
                    ]),
                    t("label", nt, [
                      t("span", it, o(e(n)("sync.warehouse")), 1),
                      w(t("select", {
                        "onUpdate:modelValue": l[6] || (l[6] = (s) => e(c).warehouse_id = s),
                        class: "shp-input"
                      }, [
                        t("option", at, o(e(n)("sync.warehouse_default")), 1),
                        (a(!0), r(h, null, S(i.warehouses, (s) => (a(), r("option", {
                          key: s.id,
                          value: s.id
                        }, o(s.name), 9, rt))), 128))
                      ], 512), [
                        [H, e(c).warehouse_id]
                      ]),
                      t("span", lt, o(e(n)("sync.warehouse_hint")), 1),
                      e(c).errors.warehouse_id ? (a(), r("span", ct, o(e(c).errors.warehouse_id), 1)) : p("", !0)
                    ])
                  ])
                ]),
                _: 1
              }),
              t("div", dt, [
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
                i.connection?.acting_user ? (a(), r("span", ut, o(e(n)("status.acting_user", { name: i.connection.acting_user })), 1)) : p("", !0)
              ])
            ], 32),
            i.connection ? (a(), r(h, { key: 1 }, [
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
                        onClick: l[7] || (l[7] = (s) => N("webhooks", "post", `${z}/webhooks`))
                      }, {
                        default: d(() => [
                          y(o(e(n)("webhooks.register")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("div", pt, [
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
                  t("p", ht, [
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
                        onClick: l[8] || (l[8] = (s) => N("sync", "post", `${z}/sync`))
                      }, {
                        default: d(() => [
                          y(o(e(n)("stock.run")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("p", _t, o(i.connection.last_full_sync_at ? e(n)("stock.last_run", { date: U(i.connection.last_full_sync_at) }) : e(n)("stock.never")), 1),
                  C.value ? (a(), r(h, { key: 0 }, [
                    t("dl", yt, [
                      (a(), r(h, null, S(["shopify_skus", "inventoros_skus", "matched", "pushed", "failed", "untracked", "only_in_shopify", "only_in_inventoros"], (s) => t("div", { key: s }, [
                        t("dt", null, o(e(n)(`stock.${s}`)), 1),
                        t("dd", null, o(C.value[s] ?? 0), 1)
                      ])), 64))
                    ]),
                    C.value.failed_sample?.length ? (a(), r("details", mt, [
                      t("summary", null, o(e(n)("stock.failed")) + " (" + o(C.value.failed) + ")", 1),
                      t("ul", null, [
                        (a(!0), r(h, null, S(C.value.failed_sample, (s) => (a(), r("li", {
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
                      t("summary", null, o(e(n)(`stock.${s}`)) + " (" + o(C.value[s]) + ")", 1),
                      t("p", ft, o(e(n)("stock.sample", { count: C.value[`${s}_sample`]?.length ?? 0 })), 1),
                      t("p", vt, [
                        (a(!0), r(h, null, S(C.value[`${s}_sample`] ?? [], (v) => (a(), r("code", { key: v }, o(v), 1))), 128))
                      ])
                    ], 512), [
                      [ae, C.value[`${s}_sample`]?.length]
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
                  t("p", gt, o(i.connection.orders_synced_at ? e(n)("recovery.last_run", { date: U(i.connection.orders_synced_at) }) : e(n)("recovery.never")), 1),
                  M.value ? (a(), r(h, { key: 0 }, [
                    t("dl", St, [
                      (a(), r(h, null, S(["checked", "imported", "updated", "not_imported"], (s) => t("div", { key: s }, [
                        t("dt", null, o(e(n)(`recovery.${s}`)), 1),
                        t("dd", null, o(M.value[s] ?? 0), 1)
                      ])), 64))
                    ]),
                    M.value.webhooks_repaired?.length ? (a(), r("p", bt, o(e(n)("recovery.webhooks_repaired", { topics: M.value.webhooks_repaired.join(", ") })), 1)) : p("", !0)
                  ], 64)) : p("", !0),
                  t("form", {
                    class: "shp-inline",
                    onSubmit: l[10] || (l[10] = W((s) => e($).post(`${z}/recover`, { preserveScroll: !0 }), ["prevent"]))
                  }, [
                    t("label", kt, [
                      t("span", wt, o(e(n)("recovery.from")), 1),
                      w(t("input", {
                        "onUpdate:modelValue": l[9] || (l[9] = (s) => e($).from = s),
                        class: "shp-input shp-input-short",
                        type: "date",
                        min: e(Y),
                        max: e(Z),
                        required: ""
                      }, null, 8, It), [
                        [T, e($).from]
                      ]),
                      t("span", Ct, o(e(n)("recovery.from_hint")), 1),
                      e($).errors.from ? (a(), r("span", zt, o(e($).errors.from), 1)) : p("", !0)
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
                  t("div", Ut, [
                    t("div", null, [
                      t("h4", Rt, o(e(n)("catalogue.import_title")), 1),
                      t("p", $t, o(e(n)("catalogue.import_hint")), 1),
                      t("label", Pt, [
                        w(t("input", {
                          "onUpdate:modelValue": l[11] || (l[11] = (s) => e(O).with_stock = s),
                          type: "checkbox"
                        }, null, 512), [
                          [L, e(O).with_stock]
                        ]),
                        t("span", xt, o(e(n)("catalogue.with_stock")), 1)
                      ]),
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected,
                        loading: e(O).processing,
                        onClick: l[12] || (l[12] = (s) => e(O).post(`${z}/catalogue/import`, { preserveScroll: !0 }))
                      }, {
                        default: d(() => [
                          y(o(e(n)("catalogue.import")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"]),
                      x.value ? (a(), r(h, { key: 0 }, [
                        t("p", Vt, o(e(n)("catalogue.last_run", { date: U(x.value.finished_at) })), 1),
                        t("dl", Tt, [
                          (a(), r(h, null, S(["products", "variants", "existing", "no_sku", "failed"], (s) => t("div", { key: s }, [
                            t("dt", null, o(e(n)(`catalogue.${s}`)), 1),
                            t("dd", null, o(x.value[s] ?? 0), 1)
                          ])), 64))
                        ]),
                        x.value.failed_sample?.length ? (a(), r("details", Dt, [
                          t("summary", null, o(e(n)("catalogue.failed")), 1),
                          t("ul", null, [
                            (a(!0), r(h, null, S(x.value.failed_sample, (s) => (a(), r("li", { key: s }, o(s), 1))), 128))
                          ])
                        ])) : p("", !0)
                      ], 64)) : p("", !0)
                    ]),
                    t("div", null, [
                      t("h4", Ot, o(e(n)("catalogue.publish_title")), 1),
                      t("p", At, o(e(n)("catalogue.publish_hint")), 1),
                      w(t("select", {
                        "onUpdate:modelValue": l[13] || (l[13] = (s) => e(A).status = s),
                        class: "shp-input shp-input-short shp-gap"
                      }, [
                        t("option", Mt, o(e(n)("catalogue.status_draft")), 1),
                        t("option", Nt, o(e(n)("catalogue.status_active")), 1)
                      ], 512), [
                        [H, e(A).status]
                      ]),
                      u(e(I), {
                        size: "sm",
                        disabled: !i.connection.connected || !i.connection.location_map?.length,
                        loading: e(A).processing,
                        onClick: l[14] || (l[14] = (s) => e(A).post(`${z}/catalogue/publish`, { preserveScroll: !0 }))
                      }, {
                        default: d(() => [
                          y(o(e(n)("catalogue.publish")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"]),
                      V.value ? (a(), r(h, { key: 0 }, [
                        t("p", Et, o(e(n)("catalogue.last_run", { date: U(V.value.finished_at) })), 1),
                        t("dl", Kt, [
                          (a(), r(h, null, S(["products", "variants", "already", "partial", "no_sku", "failed"], (s) => t("div", { key: s }, [
                            t("dt", null, o(e(n)(`catalogue.${s}`)), 1),
                            t("dd", null, o(V.value[s] ?? 0), 1)
                          ])), 64))
                        ]),
                        V.value.failed_sample?.length ? (a(), r("details", qt, [
                          t("summary", null, o(e(n)("catalogue.failed")), 1),
                          t("ul", null, [
                            (a(!0), r(h, null, S(V.value.failed_sample, (s) => (a(), r("li", { key: s }, o(s), 1))), 128))
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
                  t("div", Ft, [
                    u(e(_), {
                      title: e(n)("orders.title")
                    }, null, 8, ["title"])
                  ]),
                  i.orders.length ? (a(), r("div", Ht, [
                    t("table", Bt, [
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
                            t("span", Wt, o(U(s.updated_at)), 1)
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
                          t("td", jt, o(s.message), 1),
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
                              onClick: (v) => N(`retry-${s.id}`, "post", `${z}/orders/${s.id}/retry`)
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
                  ])) : (a(), r("p", Lt, o(e(n)("orders.empty")), 1))
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
}, Qt = /* @__PURE__ */ G(Gt, [["__scopeId", "data-v-89d259f8"]]), Jt = { class: "shp-card" }, Xt = { class: "shp-card-body" }, Yt = { class: "shp-card-title" }, Zt = { class: "shp-card-text" }, eo = {
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
            t("div", Jt, [
              _[0] || (_[0] = t("span", {
                class: "shp-card-icon",
                "aria-hidden": "true"
              }, "S", -1)),
              t("div", Xt, [
                t("h3", Yt, o(e(n)("card.title")), 1),
                t("p", Zt, o(e(n)("card.description")), 1)
              ])
            ])
          ]),
          _: 1
        })
      ]),
      _: 1
    }, 8, ["href"]));
  }
}, to = /* @__PURE__ */ G(eo, [["__scopeId", "data-v-0691ef2f"]]), oo = { title: "Shopify" }, so = { manage: { label: "Gestire Shopify", description: "Collegare un negozio Shopify, modificarne le impostazioni di sincronizzazione e ritentare l'importazione degli ordini" } }, no = { title: "Shopify", description: "Importa gli ordini Shopify e pubblica le giacenze nel negozio." }, io = { title: "Shopify", description: "Gli ordini Shopify diventano ordini Inventoros e le giacenze di Inventoros vengono pubblicate su Shopify. I prodotti sono abbinati per SKU.", crumb: "Integrazioni" }, ao = { connected: "Collegato a {shop}", not_connected: "Non collegato", error: "Ultimo errore", acting_user: "Ordini e movimenti di stock sono registrati come {name}." }, ro = { title: "Connessione", subtitle: "Le credenziali dell'app Shopify, dal Dev Dashboard (App settings).", shop_domain: "Indirizzo del negozio", shop_domain_hint: "Per esempio mio-negozio.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Salvato. Lascia vuoto per mantenerlo.", api_version: "Versione API", scopes: "L'app richiede questi scope: {scopes}.", save: "Salva e prova la connessione", disconnect: "Scollega", disconnect_confirm: "Scollegare Shopify? Gli ordini già importati restano; le credenziali salvate vengono eliminate." }, lo = { title: "Impostazioni di sincronizzazione", import_orders: "Importa gli ordini Shopify", import_orders_hint: "I nuovi ordini Shopify diventano ordini Inventoros e scalano lo stock; annullarli in Shopify li annulla anche qui.", push_inventory: "Pubblica le giacenze su Shopify", push_inventory_hint: "Ogni movimento di stock in Inventoros imposta la quantità disponibile su Shopify. Inventoros è la fonte di verità.", warehouse: "Magazzino predefinito per gli ordini Shopify", warehouse_default: "Magazzino predefinito", locations: "Sedi Shopify", locations_hint: "Scegli quale stock di Inventoros mostra ogni sede Shopify. Lascia una sede non sincronizzata per gestirne lo stock su Shopify. Con più sedi, assegna a ciascuna il proprio magazzino.", location_unsynced: "Non sincronizzata", location_all: "Tutti i magazzini (stock totale)", warehouse_hint: "Usato quando la sede Shopify dell'ordine non è collegata a un magazzino.", location_hint: "Salva prima la connessione per caricare le sedi del negozio." }, co = { title: "Webhook degli ordini", subtitle: "Shopify avvisa Inventoros dei nuovi ordini e di quelli annullati a questo indirizzo. Deve essere pubblico e HTTPS.", register: "Registra i webhook su Shopify", registered: "Registrati il {date}", not_registered: "Non ancora registrati", copy: "Copia", copied: "Copiato", checked: "Controllati il {date}" }, uo = { title: "Sincronizzazione giacenze", subtitle: "Abbina ogni variante Shopify a Inventoros tramite SKU e pubblica tutte le quantità. Eseguila una volta dopo il collegamento e ogni volta che cambi molti SKU.", run: "Sincronizza tutte le giacenze", never: "Mai eseguita", last_run: "Ultima esecuzione {date}", shopify_skus: "SKU su Shopify", inventoros_skus: "SKU su Inventoros", matched: "Abbinati", pushed: "Pubblicati", failed: "Falliti", untracked: "Non tracciati su Shopify", only_in_shopify: "Solo su Shopify", only_in_inventoros: "Solo su Inventoros", sample: "Primi {count}:" }, po = { title: "Ordini Shopify recenti", empty: "Nessun ordine Shopify ricevuto finora.", shopify_order: "Ordine Shopify", status: "Stato", result: "Esito", inventoros_order: "Ordine Inventoros", retry: "Riprova", statuses: { imported: "Importato", failed: "Fallito", skipped: "Saltato", cancelled: "Annullato" } }, ho = { title: "Recupero ordini", subtitle: "Ogni dieci minuti Inventoros rilegge gli ordini modificati su Shopify: gli ordini il cui webhook è andato perso vengono comunque importati, e modifiche, evasioni e rimborsi vengono applicati. I webhook mancanti vengono registrati di nuovo.", last_run: "Ultima esecuzione {date}", never: "Non ancora eseguito", checked: "Ordini controllati", imported: "Importati", updated: "Aggiornati", not_imported: "Non importati", ignored: "Precedenti alla finestra di importazione", webhooks_repaired: "Webhook registrati di nuovo: {topics}", from: "Importa gli ordini mancanti creati dal", from_hint: "Shopify rende disponibili alle app gli ordini degli ultimi 60 giorni.", run: "Recupera ordini" }, _o = { title: "Catalogo", subtitle: "Copia i prodotti tra Shopify e Inventoros, abbinati per SKU. I prodotti che esistono già dall'altra parte non vengono mai modificati.", import_title: "Importa da Shopify", import_hint: "Crea un prodotto Inventoros per ogni prodotto Shopify i cui SKU non esistono in Inventoros, con opzioni e varianti.", with_stock: "Importa anche lo stock disponibile su Shopify come giacenza iniziale", import: "Importa prodotti", publish_title: "Pubblica su Shopify", publish_hint: "Crea un prodotto Shopify per ogni prodotto Inventoros attivo i cui SKU non esistono su Shopify, con prezzi, barcode e stock nelle sedi collegate.", status_draft: "Come bozze", status_active: "Come prodotti attivi", publish: "Pubblica prodotti", last_run: "Ultima esecuzione {date}", products: "Prodotti", variants: "Varianti", existing: "Già in Inventoros", already: "Già su Shopify", partial: "In parte su Shopify", no_sku: "Senza SKU", failed: "Falliti" }, yo = {
  nav: oo,
  permissions: so,
  card: no,
  page: io,
  status: ao,
  connection: ro,
  sync: lo,
  webhooks: co,
  stock: uo,
  orders: po,
  recovery: ho,
  catalogue: _o
};
function ai(i) {
  i.i18n?.addMessages("en", j), i.i18n?.addMessages("it", yo), i.registerPage("Settings", Qt), i.registerComponent("ShopifySettingsCard", to);
}
export {
  ai as default
};
