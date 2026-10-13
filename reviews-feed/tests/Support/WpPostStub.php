<?php

// Minimal WP_Post for unit tests that pass a post to a WP_Post-typed method. Same constructor shape as core:
// an object whose public fields are copied on.
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PSR1.Classes.ClassDeclaration.MissingNamespace
final class WP_Post
{
	/** @var int */
	public $ID = 0;
	/** @var string */
	public $post_content = '';

	/** @param object $post */
	public function __construct($post)
	{
		foreach (get_object_vars($post) as $key => $value) {
			$this->$key = $value;
		}
	}
}
