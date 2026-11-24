export function observationPayload(query, result) {
  return {
    query,
    source_valid: result.sourceValid,
    source_warning: result.sourceWarning || '',
    seller: result.ownProducts?.length ? 'سارمو استایل' : '',
    grid_rank: result.gridRank,
    ad_rank: result.adRank,
    absolute_ad_slot: result.absoluteAdSlot,
    merchant_rank: result.merchantCarouselRank,
    scanned_products: result.scannedProducts || 0,
    scan_complete: result.scanComplete || false,
    details: {
      own_products: result.ownProducts || [],
      top_competitors: result.topCompetitors || [],
      scan_complete: result.scanComplete || false,
      rank_scope: result.rankScope || '',
    },
  };
}
