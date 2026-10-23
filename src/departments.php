<?php
// One category hierarchy powers navigation, previews and the catalog filter.
function departmentChildren(array $cats, ?int $parent=null): array {
    return array_values(array_filter($cats,fn($c)=>$c['parentId']===$parent));
}
function departmentSample(array $c): string {
    $samples=['sanitaryware','electrical','hardware','tiles','water-purifier','plumbing','water-heaters','machines-pumps'];
    return $c['cover']?:$c['imageUrl']?:(in_array($c['slug'],$samples,true)?'/category-samples/'.$c['slug'].'.webp':'/category-samples/hardware.webp');
}
function departmentLeafLinks(array $cats,int $parent): void {
    foreach(departmentChildren($cats,$parent) as $c){ ?><a data-preview="<?=e($c['slug'])?>" href="/products?category=<?=e($c['slug'])?>"><?=e($c['name'])?></a><?php departmentLeafLinks($cats,$c['id']); }
}
function catalogCategoryLink(array $c,string $active): string {
    $query=[...$_GET,'category'=>$c['slug']];unset($query['page'],$query['facet']);
    return '<a class="'.($active===$c['slug']?'active':'').'" href="/products?'.e(http_build_query($query)).'"><span>'.e($c['name']).'</span><small>'.$c['count'].'</small></a>';
}
function renderCatalogChildren(array $cats,int $parent,string $active): void {
    ?><ul><?php foreach(departmentChildren($cats,$parent) as $c):?><li><?=catalogCategoryLink($c,$active)?><?php renderCatalogChildren($cats,$c['id'],$active);?></li><?php endforeach;?></ul><?php
}
function renderCatalogTree(array $cats,string $active): void { ?>
<nav class="catalog-tree" aria-label="Product categories"><h2>Category</h2><a href="/products">All products</a><?php foreach(departmentChildren($cats) as $c):$ids=[$c['id']];do{$n=count($ids);foreach($cats as $child)if(in_array($child['parentId'],$ids,true)&&!in_array($child['id'],$ids,true))$ids[]=$child['id'];}while($n!==count($ids));$expanded=false;foreach($cats as $child)if($child['slug']===$active&&in_array($child['id'],$ids,true))$expanded=true;?><details <?=$expanded?'open':''?>><summary><?=e($c['name'])?></summary><?=catalogCategoryLink($c,$active)?><?php renderCatalogChildren($cats,$c['id'],$active);?></details><?php endforeach;?></nav>
<?php }
function renderDepartments(array $cats,array $site): void { ?>
<nav class="department-nav" aria-label="Departments">
  <div class="department-tabs wrap">
    <a href="/products"><?=copyText($site,'navigation.allProducts')?></a>
    <a href="/about"><?=copyText($site,'navigation.aboutLabel')?></a>
    <?php foreach(departmentChildren($cats) as $c):?><button type="button" class="department-trigger" data-department="<?=e($c['slug'])?>" aria-expanded="false" aria-controls="department-<?=$c['id']?>"><?=e($c['name'])?> <span aria-hidden="true">⌄</span></button><?php endforeach;?>
    <a href="/quote">Contact</a>
  </div>
  <?php foreach(departmentChildren($cats) as $c):?>
  <div id="department-<?=$c['id']?>" class="department-panel" data-slug="<?=e($c['slug'])?>" data-name="<?=e($c['name'])?>" data-sample="<?=e(departmentSample($c))?>" hidden>
    <div class="department-panel-top"><a href="/products?category=<?=e($c['slug'])?>">View all <?=e($c['name'])?> <span aria-hidden="true">→</span></a><button type="button" class="plain department-close" aria-label="Close departments">×</button></div>
    <div class="department-groups"><?php foreach(departmentChildren($cats,$c['id']) as $group):?><section><h2><a data-preview="<?=e($group['slug'])?>" href="/products?category=<?=e($group['slug'])?>"><?=e($group['name'])?></a></h2><?php departmentLeafLinks($cats,$group['id']);?></section><?php endforeach;?></div>
    <section class="department-preview" aria-label="Product preview"><div class="preview-heading"><h2><?=e($c['name'])?></h2><a class="preview-all" href="/products?category=<?=e($c['slug'])?>">View all</a></div><div class="preview-scroll" tabindex="0" role="region" aria-label="Browse department products"><div class="preview-content" aria-live="polite"></div><button type="button" class="secondary preview-more" hidden>Load more products</button></div></section>
  </div><?php endforeach;?>
</nav>
<?php }
