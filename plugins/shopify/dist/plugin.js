const j = window.Inventoros.Vue, { BaseTransition: Rt, BaseTransitionPropsValidators: $t, Comment: Tt, DeprecationTypes: Ut, EffectScope: xt, ErrorCodes: Pt, ErrorTypeStrings: Vt, Fragment: S, KeepAlive: Dt, ReactiveEffect: Mt, Static: At, Suspense: Et, Teleport: Ot, Text: Nt, TrackOpTypes: qt, Transition: Ht, TransitionGroup: Kt, TriggerOpTypes: Ft, VueElement: Bt, __esModule: Lt, assertNumber: Wt, callWithAsyncErrorHandling: jt, callWithErrorHandling: Gt, camelize: Qt, capitalize: Jt, cloneVNode: Xt, compatUtils: Yt, compile: Zt, computed: G, createApp: eo, createBlock: z, createCommentVNode: h, createElementBlock: l, createElementVNode: t, createHydrationRenderer: to, createPropsRestProxy: oo, createRenderer: no, createSSRApp: so, createSlots: io, createStaticVNode: ro, createTextVNode: v, createVNode: u, customRef: ao, defineAsyncComponent: lo, defineComponent: co, defineCustomElement: uo, defineEmits: po, defineExpose: ho, defineModel: _o, defineOptions: yo, defineProps: fo, defineSSRCustomElement: vo, defineSlots: mo, devtools: go, effect: So, effectScope: bo, getCurrentInstance: ko, getCurrentScope: wo, getCurrentWatcher: Co, getTransitionRawChildren: Io, guardReactiveProps: zo, h: Ro, handleError: $o, hasInjectionContext: To, hydrate: Uo, hydrateOnIdle: xo, hydrateOnInteraction: Po, hydrateOnMediaQuery: Vo, hydrateOnVisible: Do, initCustomFormatter: Mo, initDirectivesForSSR: Ao, inject: Eo, isMemoSame: Oo, isProxy: No, isReactive: qo, isReadonly: Ho, isRef: Ko, isRuntimeOnly: Fo, isShallow: Bo, isVNode: Lo, markRaw: Wo, mergeDefaults: jo, mergeModels: Go, mergeProps: Qo, nextTick: Jo, nodeOps: Xo, normalizeClass: Yo, normalizeProps: Zo, normalizeStyle: en, onActivated: tn, onBeforeMount: on, onBeforeUnmount: nn, onBeforeUpdate: sn, onDeactivated: rn, onErrorCaptured: an, onMounted: ln, onRenderTracked: cn, onRenderTriggered: dn, onScopeDispose: un, onServerPrefetch: pn, onUnmounted: hn, onUpdated: _n, onWatcherCleanup: yn, openBlock: r, patchProp: fn, popScopeId: vn, provide: mn, proxyRefs: gn, pushScopeId: Sn, queuePostFlushCb: bn, reactive: kn, readonly: wn, ref: D, registerRuntimeCompiler: Cn, render: In, renderList: w, renderSlot: zn, resolveComponent: Rn, resolveDirective: $n, resolveDynamicComponent: Tn, resolveFilter: Un, resolveTransitionHooks: xn, setBlockTracking: Pn, setDevtoolsHook: Vn, setTransitionHooks: Dn, shallowReactive: Mn, shallowReadonly: An, shallowRef: En, ssrContextKey: On, ssrUtils: Nn, stop: qn, toDisplayString: o, toHandlerKey: Hn, toHandlers: Kn, toRaw: Fn, toRef: Bn, toRefs: Ln, toValue: Wn, transformVNodeArgs: jn, triggerRef: Gn, unref: e, useAttrs: Qn, useCssModule: Jn, useCssVars: Xn, useHost: Yn, useId: Zn, useModel: es, useSSRContext: ts, useShadowRoot: os, useSlots: ns, useTemplateRef: ss, useTransitionState: is, vModelCheckbox: M, vModelDynamic: rs, vModelRadio: as, vModelSelect: A, vModelText: U, vShow: Q, version: ls, warn: cs, watch: ds, watchEffect: us, watchPostEffect: ps, watchSyncEffect: hs, withAsyncContext: _s, withCtx: d, withDefaults: ys, withDirectives: k, withKeys: fs, withMemo: vs, withModifiers: J, withScopeId: ms } = j, X = { title: "Shopify" }, Y = { manage: { label: "Manage Shopify", description: "Connect a Shopify store, change its sync settings and retry order imports" } }, Z = { title: "Shopify", description: "Import Shopify orders and push stock to your store." }, ee = { title: "Shopify", description: "Shopify orders become Inventoros orders, and Inventoros stock is published to Shopify. Products are matched by SKU.", crumb: "Integrations" }, te = { connected: "Connected to {shop}", not_connected: "Not connected", error: "Last error", acting_user: "Orders and stock changes are recorded as {name}." }, oe = { title: "Connection", subtitle: "The Shopify app's credentials, from the Dev Dashboard (App settings).", shop_domain: "Store address", shop_domain_hint: "For example my-store.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Saved. Leave empty to keep it.", api_version: "API version", scopes: "The app needs these scopes: {scopes}.", save: "Save and test connection", disconnect: "Disconnect", disconnect_confirm: "Disconnect Shopify? Orders already imported stay; the stored credentials are deleted." }, ne = { title: "Sync settings", import_orders: "Import Shopify orders", import_orders_hint: "New Shopify orders become Inventoros orders and take stock out; cancelling in Shopify cancels them here.", push_inventory: "Push stock to Shopify", push_inventory_hint: "Every stock change in Inventoros sets the available quantity in Shopify. Inventoros is the source of truth.", location: "Shopify location", location_none: "Choose a location", location_hint: "Save the connection first to load the store's locations.", warehouse: "Inventoros warehouse for Shopify orders", warehouse_default: "Default warehouse" }, se = { title: "Order webhooks", subtitle: "Shopify notifies Inventoros of new and cancelled orders at this address. It must be public and HTTPS.", register: "Register webhooks in Shopify", registered: "Registered {date}", not_registered: "Not registered yet", copy: "Copy", copied: "Copied" }, ie = { title: "Stock sync", subtitle: "Match every Shopify variant to Inventoros by SKU and push all quantities. Run it once after connecting, and whenever SKUs change in bulk.", run: "Sync all stock now", never: "Never run", last_run: "Last run {date}", shopify_skus: "Shopify SKUs", inventoros_skus: "Inventoros SKUs", matched: "Matched", pushed: "Pushed", failed: "Failed", untracked: "Not tracked in Shopify", only_in_shopify: "Only in Shopify", only_in_inventoros: "Only in Inventoros", sample: "First {count}:" }, re = { title: "Recent Shopify orders", empty: "No Shopify orders received yet.", shopify_order: "Shopify order", status: "Status", result: "Result", inventoros_order: "Inventoros order", retry: "Retry", statuses: { imported: "Imported", failed: "Failed", skipped: "Skipped", cancelled: "Cancelled" } }, E = {
  nav: X,
  permissions: Y,
  card: Z,
  page: ee,
  status: te,
  connection: oe,
  sync: ne,
  webhooks: se,
  stock: ie,
  orders: re
}, ae = () => window.Inventoros?.plugin?.("shopify");
function le(i, y) {
  const f = i.split(".").reduce((_, p) => _ && typeof _ == "object" ? _[p] : void 0, E);
  return typeof f != "string" ? i : f.replace(/\{(\w+)\}/g, (_, p) => y && p in y ? String(y[p]) : _);
}
function s(i, y = {}) {
  const f = ae()?.i18n;
  return f?.te?.(i) ? f.t(i, y) : le(i, y);
}
const O = (i, y) => {
  const f = i.__vccOpts || i;
  for (const [_, p] of y)
    f[_] = p;
  return f;
}, ce = { class: "shp-crumbs" }, de = { class: "shp-page" }, ue = {
  key: 0,
  class: "shp-alert",
  role: "alert"
}, pe = { class: "shp-fields" }, he = { class: "shp-field" }, _e = { class: "shp-label" }, ye = ["placeholder"], fe = {
  key: 0,
  class: "shp-error"
}, ve = { class: "shp-field" }, me = { class: "shp-label" }, ge = {
  key: 0,
  class: "shp-error"
}, Se = { class: "shp-field" }, be = { class: "shp-label" }, ke = ["placeholder", "required"], we = {
  key: 0,
  class: "shp-hint"
}, Ce = {
  key: 1,
  class: "shp-error"
}, Ie = { class: "shp-field" }, ze = { class: "shp-label" }, Re = {
  key: 0,
  class: "shp-error"
}, $e = { class: "shp-hint" }, Te = { class: "shp-fields" }, Ue = { class: "shp-check" }, xe = { class: "shp-label" }, Pe = { class: "shp-hint" }, Ve = { class: "shp-check" }, De = { class: "shp-label" }, Me = { class: "shp-hint" }, Ae = { class: "shp-field" }, Ee = { class: "shp-label" }, Oe = ["disabled"], Ne = { value: "" }, qe = ["value"], He = {
  key: 0,
  class: "shp-hint"
}, Ke = { class: "shp-field" }, Fe = { class: "shp-label" }, Be = { value: "" }, Le = ["value"], We = {
  key: 0,
  class: "shp-error"
}, je = { class: "shp-actions" }, Ge = {
  key: 1,
  class: "shp-hint"
}, Qe = { class: "shp-url" }, Je = { class: "shp-hint" }, Xe = { class: "shp-hint" }, Ye = { class: "shp-stats" }, Ze = {
  key: 0,
  class: "shp-details"
}, et = { class: "shp-hint" }, tt = { class: "shp-skus" }, ot = { class: "shp-table-header" }, nt = {
  key: 0,
  class: "shp-empty"
}, st = {
  key: 1,
  class: "shp-table-wrap"
}, it = { class: "shp-table" }, rt = { class: "shp-hint" }, at = { class: "shp-message" }, $ = "/p/shopify/actions", lt = {
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
      layouts: { AppLayout: y },
      ui: { PageHeader: f, Card: _, CardHeader: p, Button: C, Badge: x },
      Inertia: { Head: N, Link: q, router: H, useForm: K }
    } = window.Inventoros, g = i, a = K({
      shop_domain: g.connection?.shop_domain ?? "",
      client_id: g.connection?.client_id ?? "",
      client_secret: "",
      api_version: g.connection?.api_version ?? g.defaultApiVersion,
      location_id: g.connection?.location_id ?? "",
      warehouse_id: g.connection?.warehouse_id ?? "",
      import_orders: g.connection?.import_orders ?? !0,
      push_inventory: g.connection?.push_inventory ?? !0
    }), I = D(null), P = D(!1), b = G(() => g.connection?.last_full_sync_summary ?? null);
    function F() {
      a.transform((m) => ({
        ...m,
        location_id: m.location_id || null,
        warehouse_id: m.warehouse_id || null
      })).post(`${$}/settings`, {
        preserveScroll: !0,
        onSuccess: () => a.reset("client_secret")
      });
    }
    function T(m, c, n, R = {}) {
      I.value = m, H.visit(n, {
        method: c,
        preserveScroll: !0,
        ...R,
        onFinish: () => {
          I.value = null;
        }
      });
    }
    function B() {
      window.confirm(s("connection.disconnect_confirm")) && T("disconnect", "delete", `${$}/connection`);
    }
    async function L() {
      try {
        await navigator.clipboard.writeText(g.webhookUrl), P.value = !0, setTimeout(() => P.value = !1, 2e3);
      } catch {
      }
    }
    function V(m) {
      return m ? new Date(m).toLocaleString() : "";
    }
    const W = (m) => ({ imported: "success", failed: "danger", skipped: "warning", cancelled: "neutral" })[m] ?? "neutral";
    return (m, c) => (r(), l(S, null, [
      u(e(N), { title: i.title }, null, 8, ["title"]),
      u(e(y), null, {
        header: d(() => [
          t("div", ce, [
            t("span", null, o(e(s)("page.crumb")), 1),
            c[10] || (c[10] = t("span", null, "/", -1)),
            t("strong", null, o(i.title), 1)
          ])
        ]),
        default: d(() => [
          t("div", de, [
            u(e(f), {
              title: e(s)("page.title"),
              description: e(s)("page.description")
            }, {
              actions: d(() => [
                i.connection?.connected ? (r(), z(e(x), {
                  key: 0,
                  variant: "success",
                  dot: ""
                }, {
                  default: d(() => [
                    v(o(e(s)("status.connected", { shop: i.connection.shop_name })), 1)
                  ]),
                  _: 1
                })) : (r(), z(e(x), {
                  key: 1,
                  variant: "neutral",
                  dot: ""
                }, {
                  default: d(() => [
                    v(o(e(s)("status.not_connected")), 1)
                  ]),
                  _: 1
                }))
              ]),
              _: 1
            }, 8, ["title", "description"]),
            i.connection?.last_error ? (r(), l("div", ue, [
              t("strong", null, o(e(s)("status.error")) + ":", 1),
              v(" " + o(i.connection.last_error), 1)
            ])) : h("", !0),
            t("form", {
              class: "shp-grid",
              onSubmit: J(F, ["prevent"])
            }, [
              u(e(_), null, {
                default: d(() => [
                  u(e(p), {
                    title: e(s)("connection.title"),
                    subtitle: e(s)("connection.subtitle")
                  }, null, 8, ["title", "subtitle"]),
                  t("div", pe, [
                    t("label", he, [
                      t("span", _e, o(e(s)("connection.shop_domain")), 1),
                      k(t("input", {
                        "onUpdate:modelValue": c[0] || (c[0] = (n) => e(a).shop_domain = n),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        placeholder: e(s)("connection.shop_domain_hint"),
                        required: ""
                      }, null, 8, ye), [
                        [U, e(a).shop_domain]
                      ]),
                      e(a).errors.shop_domain ? (r(), l("span", fe, o(e(a).errors.shop_domain), 1)) : h("", !0)
                    ]),
                    t("label", ve, [
                      t("span", me, o(e(s)("connection.client_id")), 1),
                      k(t("input", {
                        "onUpdate:modelValue": c[1] || (c[1] = (n) => e(a).client_id = n),
                        class: "shp-input",
                        type: "text",
                        autocomplete: "off",
                        required: ""
                      }, null, 512), [
                        [U, e(a).client_id]
                      ]),
                      e(a).errors.client_id ? (r(), l("span", ge, o(e(a).errors.client_id), 1)) : h("", !0)
                    ]),
                    t("label", Se, [
                      t("span", be, o(e(s)("connection.client_secret")), 1),
                      k(t("input", {
                        "onUpdate:modelValue": c[2] || (c[2] = (n) => e(a).client_secret = n),
                        class: "shp-input",
                        type: "password",
                        autocomplete: "new-password",
                        placeholder: i.connection?.has_client_secret ? "••••••••" : "",
                        required: !i.connection?.has_client_secret
                      }, null, 8, ke), [
                        [U, e(a).client_secret]
                      ]),
                      i.connection?.has_client_secret ? (r(), l("span", we, o(e(s)("connection.client_secret_saved")), 1)) : h("", !0),
                      e(a).errors.client_secret ? (r(), l("span", Ce, o(e(a).errors.client_secret), 1)) : h("", !0)
                    ]),
                    t("label", Ie, [
                      t("span", ze, o(e(s)("connection.api_version")), 1),
                      k(t("input", {
                        "onUpdate:modelValue": c[3] || (c[3] = (n) => e(a).api_version = n),
                        class: "shp-input shp-input-short",
                        type: "text",
                        pattern: "\\d{4}-\\d{2}"
                      }, null, 512), [
                        [U, e(a).api_version]
                      ]),
                      e(a).errors.api_version ? (r(), l("span", Re, o(e(a).errors.api_version), 1)) : h("", !0)
                    ]),
                    t("p", $e, o(e(s)("connection.scopes", { scopes: i.requiredScopes.join(", ") })), 1)
                  ])
                ]),
                _: 1
              }),
              u(e(_), null, {
                default: d(() => [
                  u(e(p), {
                    title: e(s)("sync.title")
                  }, null, 8, ["title"]),
                  t("div", Te, [
                    t("label", Ue, [
                      k(t("input", {
                        "onUpdate:modelValue": c[4] || (c[4] = (n) => e(a).import_orders = n),
                        type: "checkbox"
                      }, null, 512), [
                        [M, e(a).import_orders]
                      ]),
                      t("span", null, [
                        t("span", xe, o(e(s)("sync.import_orders")), 1),
                        t("span", Pe, o(e(s)("sync.import_orders_hint")), 1)
                      ])
                    ]),
                    t("label", Ve, [
                      k(t("input", {
                        "onUpdate:modelValue": c[5] || (c[5] = (n) => e(a).push_inventory = n),
                        type: "checkbox"
                      }, null, 512), [
                        [M, e(a).push_inventory]
                      ]),
                      t("span", null, [
                        t("span", De, o(e(s)("sync.push_inventory")), 1),
                        t("span", Me, o(e(s)("sync.push_inventory_hint")), 1)
                      ])
                    ]),
                    t("label", Ae, [
                      t("span", Ee, o(e(s)("sync.location")), 1),
                      k(t("select", {
                        "onUpdate:modelValue": c[6] || (c[6] = (n) => e(a).location_id = n),
                        class: "shp-input",
                        disabled: !i.connection?.locations?.length
                      }, [
                        t("option", Ne, o(e(s)("sync.location_none")), 1),
                        (r(!0), l(S, null, w(i.connection?.locations ?? [], (n) => (r(), l("option", {
                          key: n.id,
                          value: n.id
                        }, o(n.name), 9, qe))), 128))
                      ], 8, Oe), [
                        [A, e(a).location_id]
                      ]),
                      i.connection?.locations?.length ? h("", !0) : (r(), l("span", He, o(e(s)("sync.location_hint")), 1))
                    ]),
                    t("label", Ke, [
                      t("span", Fe, o(e(s)("sync.warehouse")), 1),
                      k(t("select", {
                        "onUpdate:modelValue": c[7] || (c[7] = (n) => e(a).warehouse_id = n),
                        class: "shp-input"
                      }, [
                        t("option", Be, o(e(s)("sync.warehouse_default")), 1),
                        (r(!0), l(S, null, w(i.warehouses, (n) => (r(), l("option", {
                          key: n.id,
                          value: n.id
                        }, o(n.name), 9, Le))), 128))
                      ], 512), [
                        [A, e(a).warehouse_id]
                      ]),
                      e(a).errors.warehouse_id ? (r(), l("span", We, o(e(a).errors.warehouse_id), 1)) : h("", !0)
                    ])
                  ])
                ]),
                _: 1
              }),
              t("div", je, [
                u(e(C), {
                  type: "submit",
                  loading: e(a).processing
                }, {
                  default: d(() => [
                    v(o(e(s)("connection.save")), 1)
                  ]),
                  _: 1
                }, 8, ["loading"]),
                i.connection ? (r(), z(e(C), {
                  key: 0,
                  variant: "ghost",
                  loading: I.value === "disconnect",
                  onClick: B
                }, {
                  default: d(() => [
                    v(o(e(s)("connection.disconnect")), 1)
                  ]),
                  _: 1
                }, 8, ["loading"])) : h("", !0),
                i.connection?.acting_user ? (r(), l("span", Ge, o(e(s)("status.acting_user", { name: i.connection.acting_user })), 1)) : h("", !0)
              ])
            ], 32),
            i.connection ? (r(), l(S, { key: 1 }, [
              u(e(_), { class: "shp-section" }, {
                default: d(() => [
                  u(e(p), {
                    title: e(s)("webhooks.title"),
                    subtitle: e(s)("webhooks.subtitle")
                  }, {
                    actions: d(() => [
                      u(e(C), {
                        size: "sm",
                        disabled: !i.connection.connected,
                        loading: I.value === "webhooks",
                        onClick: c[8] || (c[8] = (n) => T("webhooks", "post", `${$}/webhooks`))
                      }, {
                        default: d(() => [
                          v(o(e(s)("webhooks.register")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("div", Qe, [
                    t("code", null, o(i.webhookUrl), 1),
                    u(e(C), {
                      size: "xs",
                      variant: "outline",
                      onClick: L
                    }, {
                      default: d(() => [
                        v(o(P.value ? e(s)("webhooks.copied") : e(s)("webhooks.copy")), 1)
                      ]),
                      _: 1
                    })
                  ]),
                  t("p", Je, o(i.connection.webhooks_registered_at ? e(s)("webhooks.registered", { date: V(i.connection.webhooks_registered_at) }) : e(s)("webhooks.not_registered")), 1)
                ]),
                _: 1
              }),
              u(e(_), { class: "shp-section" }, {
                default: d(() => [
                  u(e(p), {
                    title: e(s)("stock.title"),
                    subtitle: e(s)("stock.subtitle")
                  }, {
                    actions: d(() => [
                      u(e(C), {
                        size: "sm",
                        disabled: !i.connection.connected || !i.connection.push_inventory || !i.connection.location_id,
                        loading: I.value === "sync",
                        onClick: c[9] || (c[9] = (n) => T("sync", "post", `${$}/sync`))
                      }, {
                        default: d(() => [
                          v(o(e(s)("stock.run")), 1)
                        ]),
                        _: 1
                      }, 8, ["disabled", "loading"])
                    ]),
                    _: 1
                  }, 8, ["title", "subtitle"]),
                  t("p", Xe, o(i.connection.last_full_sync_at ? e(s)("stock.last_run", { date: V(i.connection.last_full_sync_at) }) : e(s)("stock.never")), 1),
                  b.value ? (r(), l(S, { key: 0 }, [
                    t("dl", Ye, [
                      (r(), l(S, null, w(["shopify_skus", "inventoros_skus", "matched", "pushed", "failed", "untracked", "only_in_shopify", "only_in_inventoros"], (n) => t("div", { key: n }, [
                        t("dt", null, o(e(s)(`stock.${n}`)), 1),
                        t("dd", null, o(b.value[n] ?? 0), 1)
                      ])), 64))
                    ]),
                    b.value.failed_sample?.length ? (r(), l("details", Ze, [
                      t("summary", null, o(e(s)("stock.failed")) + " (" + o(b.value.failed) + ")", 1),
                      t("ul", null, [
                        (r(!0), l(S, null, w(b.value.failed_sample, (n) => (r(), l("li", {
                          key: n.sku
                        }, [
                          t("code", null, o(n.sku), 1),
                          v(": " + o(n.error), 1)
                        ]))), 128))
                      ])
                    ])) : h("", !0),
                    (r(), l(S, null, w(["untracked", "only_in_shopify", "only_in_inventoros"], (n) => k(t("details", {
                      key: n,
                      class: "shp-details"
                    }, [
                      t("summary", null, o(e(s)(`stock.${n}`)) + " (" + o(b.value[n]) + ")", 1),
                      t("p", et, o(e(s)("stock.sample", { count: b.value[`${n}_sample`]?.length ?? 0 })), 1),
                      t("p", tt, [
                        (r(!0), l(S, null, w(b.value[`${n}_sample`] ?? [], (R) => (r(), l("code", { key: R }, o(R), 1))), 128))
                      ])
                    ], 512), [
                      [Q, b.value[`${n}_sample`]?.length]
                    ])), 64))
                  ], 64)) : h("", !0)
                ]),
                _: 1
              }),
              u(e(_), {
                class: "shp-section",
                padded: !1
              }, {
                default: d(() => [
                  t("div", ot, [
                    u(e(p), {
                      title: e(s)("orders.title")
                    }, null, 8, ["title"])
                  ]),
                  i.orders.length ? (r(), l("div", st, [
                    t("table", it, [
                      t("thead", null, [
                        t("tr", null, [
                          t("th", null, o(e(s)("orders.shopify_order")), 1),
                          t("th", null, o(e(s)("orders.status")), 1),
                          t("th", null, o(e(s)("orders.result")), 1),
                          t("th", null, o(e(s)("orders.inventoros_order")), 1),
                          c[11] || (c[11] = t("th", null, null, -1))
                        ])
                      ]),
                      t("tbody", null, [
                        (r(!0), l(S, null, w(i.orders, (n) => (r(), l("tr", {
                          key: n.id
                        }, [
                          t("td", null, [
                            t("strong", null, o(n.name), 1),
                            t("span", rt, o(V(n.updated_at)), 1)
                          ]),
                          t("td", null, [
                            u(e(x), {
                              variant: W(n.status),
                              size: "sm"
                            }, {
                              default: d(() => [
                                v(o(e(s)(`orders.statuses.${n.status}`)), 1)
                              ]),
                              _: 2
                            }, 1032, ["variant"])
                          ]),
                          t("td", at, o(n.message), 1),
                          t("td", null, [
                            n.order_url ? (r(), z(e(q), {
                              key: 0,
                              href: n.order_url
                            }, {
                              default: d(() => [
                                v(o(n.order_number), 1)
                              ]),
                              _: 2
                            }, 1032, ["href"])) : h("", !0)
                          ]),
                          t("td", null, [
                            n.retryable ? (r(), z(e(C), {
                              key: 0,
                              size: "xs",
                              variant: "outline",
                              loading: I.value === `retry-${n.id}`,
                              onClick: (R) => T(`retry-${n.id}`, "post", `${$}/orders/${n.id}/retry`)
                            }, {
                              default: d(() => [
                                v(o(e(s)("orders.retry")), 1)
                              ]),
                              _: 1
                            }, 8, ["loading", "onClick"])) : h("", !0)
                          ])
                        ]))), 128))
                      ])
                    ])
                  ])) : (r(), l("p", nt, o(e(s)("orders.empty")), 1))
                ]),
                _: 1
              })
            ], 64)) : h("", !0)
          ])
        ]),
        _: 1
      })
    ], 64));
  }
}, ct = /* @__PURE__ */ O(lt, [["__scopeId", "data-v-296e6071"]]), dt = { class: "shp-card" }, ut = { class: "shp-card-body" }, pt = { class: "shp-card-title" }, ht = { class: "shp-card-text" }, _t = {
  __name: "ShopifySettingsCard",
  props: {
    url: { type: String, required: !0 }
  },
  setup(i) {
    const {
      ui: { Card: y },
      Inertia: { Link: f }
    } = window.Inventoros;
    return (_, p) => (r(), z(e(f), { href: i.url }, {
      default: d(() => [
        u(e(y), { hoverable: "" }, {
          default: d(() => [
            t("div", dt, [
              p[0] || (p[0] = t("span", {
                class: "shp-card-icon",
                "aria-hidden": "true"
              }, "S", -1)),
              t("div", ut, [
                t("h3", pt, o(e(s)("card.title")), 1),
                t("p", ht, o(e(s)("card.description")), 1)
              ])
            ])
          ]),
          _: 1
        })
      ]),
      _: 1
    }, 8, ["href"]));
  }
}, yt = /* @__PURE__ */ O(_t, [["__scopeId", "data-v-0691ef2f"]]), ft = { title: "Shopify" }, vt = { manage: { label: "Gestire Shopify", description: "Collegare un negozio Shopify, modificarne le impostazioni di sincronizzazione e ritentare l'importazione degli ordini" } }, mt = { title: "Shopify", description: "Importa gli ordini Shopify e pubblica le giacenze nel negozio." }, gt = { title: "Shopify", description: "Gli ordini Shopify diventano ordini Inventoros e le giacenze di Inventoros vengono pubblicate su Shopify. I prodotti sono abbinati per SKU.", crumb: "Integrazioni" }, St = { connected: "Collegato a {shop}", not_connected: "Non collegato", error: "Ultimo errore", acting_user: "Ordini e movimenti di stock sono registrati come {name}." }, bt = { title: "Connessione", subtitle: "Le credenziali dell'app Shopify, dal Dev Dashboard (App settings).", shop_domain: "Indirizzo del negozio", shop_domain_hint: "Per esempio mio-negozio.myshopify.com", client_id: "Client ID", client_secret: "Client secret", client_secret_saved: "Salvato. Lascia vuoto per mantenerlo.", api_version: "Versione API", scopes: "L'app richiede questi scope: {scopes}.", save: "Salva e prova la connessione", disconnect: "Scollega", disconnect_confirm: "Scollegare Shopify? Gli ordini già importati restano; le credenziali salvate vengono eliminate." }, kt = { title: "Impostazioni di sincronizzazione", import_orders: "Importa gli ordini Shopify", import_orders_hint: "I nuovi ordini Shopify diventano ordini Inventoros e scalano lo stock; annullarli in Shopify li annulla anche qui.", push_inventory: "Pubblica le giacenze su Shopify", push_inventory_hint: "Ogni movimento di stock in Inventoros imposta la quantità disponibile su Shopify. Inventoros è la fonte di verità.", location: "Sede Shopify", location_none: "Scegli una sede", location_hint: "Salva prima la connessione per caricare le sedi del negozio.", warehouse: "Magazzino Inventoros per gli ordini Shopify", warehouse_default: "Magazzino predefinito" }, wt = { title: "Webhook degli ordini", subtitle: "Shopify avvisa Inventoros dei nuovi ordini e di quelli annullati a questo indirizzo. Deve essere pubblico e HTTPS.", register: "Registra i webhook su Shopify", registered: "Registrati il {date}", not_registered: "Non ancora registrati", copy: "Copia", copied: "Copiato" }, Ct = { title: "Sincronizzazione giacenze", subtitle: "Abbina ogni variante Shopify a Inventoros tramite SKU e pubblica tutte le quantità. Eseguila una volta dopo il collegamento e ogni volta che cambi molti SKU.", run: "Sincronizza tutte le giacenze", never: "Mai eseguita", last_run: "Ultima esecuzione {date}", shopify_skus: "SKU su Shopify", inventoros_skus: "SKU su Inventoros", matched: "Abbinati", pushed: "Pubblicati", failed: "Falliti", untracked: "Non tracciati su Shopify", only_in_shopify: "Solo su Shopify", only_in_inventoros: "Solo su Inventoros", sample: "Primi {count}:" }, It = { title: "Ordini Shopify recenti", empty: "Nessun ordine Shopify ricevuto finora.", shopify_order: "Ordine Shopify", status: "Stato", result: "Esito", inventoros_order: "Ordine Inventoros", retry: "Riprova", statuses: { imported: "Importato", failed: "Fallito", skipped: "Saltato", cancelled: "Annullato" } }, zt = {
  nav: ft,
  permissions: vt,
  card: mt,
  page: gt,
  status: St,
  connection: bt,
  sync: kt,
  webhooks: wt,
  stock: Ct,
  orders: It
};
function gs(i) {
  i.i18n?.addMessages("en", E), i.i18n?.addMessages("it", zt), i.registerPage("Settings", ct), i.registerComponent("ShopifySettingsCard", yt);
}
export {
  gs as default
};
