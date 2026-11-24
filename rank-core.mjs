export function normalizeFa(value = '') {
  return String(value)
    .replace(/ي/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[\u200c\u200d]/g, ' ')
    .replace(/\s+/g, ' ').trim().toLowerCase();
}

export function productKey(product) {
  return [product.image, normalizeFa(product.merchant), normalizeFa(product.title)].join('|');
}

export function ownsProduct(product, identity) {
  const merchant = normalizeFa(product.merchant);
  const aliases = (identity.merchantAliases || []).map(normalizeFa);
  if (aliases.includes(merchant)) return true;
  return (identity.productKeys || []).includes(productKey(product));
}

export function summarizeRank(snapshot, identity) {
  const ownMerchant = (snapshot.merchants || []).findIndex(name =>
    (identity.merchantAliases || []).map(normalizeFa).includes(normalizeFa(name)));
  const products = snapshot.products || [];
  const own = products.map((product, index) => ({ product, index }))
    .filter(({ product }) => ownsProduct(product, identity));
  const ads = products.filter(product => product.isAd);
  const first = own[0];
  const banner = (snapshot.banners || []).findIndex(name =>
    (identity.merchantAliases || []).map(normalizeFa).includes(normalizeFa(name)));
  const queryMatches = normalizeFa(snapshot.displayedQuery) === normalizeFa(snapshot.query);
  // An incomplete scan cannot prove that an absent product lost its position.
  const sourceValid = !snapshot.sourceWarning && queryMatches && products.length > 0 &&
    snapshot.sequenceReliable === true && (own.length > 0 || snapshot.scanComplete === true);
  const firstOwnAd = own.find(({ product }) => product.isAd);
  return {
    query: snapshot.query,
    observedAt: snapshot.observedAt,
    sourceValid,
    sourceWarning: snapshot.sourceWarning || null,
    scannedProducts: products.length,
    scanComplete: snapshot.scanComplete === true,
    merchantCarouselRank: ownMerchant < 0 ? null : ownMerchant + 1,
    bannerRank: banner < 0 ? null : banner + 1,
    gridRank: first ? first.index + 1 : null,
    adRank: firstOwnAd
      ? ads.findIndex(p => productKey(p) === productKey(firstOwnAd.product)) + 1 : null,
    ownProducts: own.map(({ product, index }) => ({
      rank: index + 1, merchant: product.merchant, title: product.title,
      isAd: !!product.isAd, key: productKey(product),
    })),
    topCompetitors: products.map((product, index) => ({
      rank: index + 1, merchant: product.merchant, title: product.title,
      isAd: !!product.isAd,
    })).filter(product => !(identity.merchantAliases || []).map(normalizeFa)
      .includes(normalizeFa(product.merchant))).slice(0, 20),
    rankScope: snapshot.scanComplete ? 'all_loaded_results' : 'first_scanned_products',
  };
}
