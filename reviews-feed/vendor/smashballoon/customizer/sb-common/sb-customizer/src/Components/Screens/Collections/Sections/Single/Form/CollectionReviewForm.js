import { __ } from "@wordpress/i18n";
import Button from "../../../../../Common/Button";
import Input from "../../../../../Common/Input";
import Select from "../../../../../Common/Select";
import Textarea from "../../../../../Common/Textarea";
import SbUtils from "../../../../../../Utils/SbUtils";
import Notice from "../../../../../Global/Notice";
import { useContext, useState } from "react";

const CollectionReviewsForm = ( props ) => {
	const {
		sbCustomizer,
		isPro,
        curRevF,
		curCl,
		allowedTiers
	} = useContext( SbUtils.getCurrentContext() );
	const providers = isPro ? sbCustomizer.providers : sbCustomizer.providers.filter( pr => allowedTiers.tiers.includes( pr.type ) );
	const providerOptions = () => {
		let options = ['none'];
		providers.forEach(pr => {
			options.push(pr.type);
		});
		return options;
	}
	const [providersOptions, setProvidersOptions] = useState(providerOptions());
	const onChangeReviewForm = (type, value) => {
		if (curRevF.currentReviewForm[type] === undefined) {
			curRevF.currentReviewForm[type] = value;
		}
		curRevF.setCurrentReviewForm({
            ...curRevF.currentReviewForm,
            [type]: value
        });
	}

	//Media Chooser for User Avatar
	let wpMediaAvatar = window.wp.media({
		title: __( 'Select Reviewer Avatar Image', 'reviews-feed' ),
		button: {
			text: __( 'Use this media', 'reviews-feed' ),
		},
		multiple: false,
	});
	const openMediaLibrary = () => {
		wpMediaAvatar.open()
	}

	wpMediaAvatar.on( 'select', function() {
		const attachment = wpMediaAvatar.state().get('selection').first().toJSON();
		if (attachment?.url) {
			onChangeReviewForm('reviewer', {
				...curRevF?.currentReviewForm?.reviewer,
				avatar : attachment?.url
			})
		}
	})



	//Media Chooser for Reviews Images
	let wpMediaReviewImages = window.wp.media({
		title: __( 'Select Reviews Image', 'reviews-feed' ),
		button: {
			text: __( 'Add Image to Review', 'reviews-feed' ),
		},
		multiple: true,
	});

	const openMediaLibraryReviewImage = () => {
		if (curRevF?.currentReviewForm?.media === undefined || curRevF?.currentReviewForm?.media === null) {
			onChangeReviewForm('media', [])
		}
		wpMediaReviewImages.open();

	}

	wpMediaReviewImages.on( 'select', function() {
		const attachments = wpMediaReviewImages.state().get('selection').map((attachment) => { return attachment.toJSON() });
		let imagesChosen = [];
		const images = attachments.map(sImage => {
			return sImage.url
		});

		if (curRevF?.currentReviewForm?.media.length === 0 ) {
			imagesChosen = images.map(sImageUrl => {
				return {
					type : 'image',
					url : sImageUrl
				}
			});
		} else {
			const existingImages = curRevF?.currentReviewForm?.media.map((exImage) => {
				return exImage.url;
			})
			let concatImagesArray = existingImages.concat(images);
			let mergedImages = [...new Set(concatImagesArray)]
			imagesChosen = mergedImages.map(sImageUrl => {
				return {
					type : 'image',
					url : sImageUrl
				}
			});
		}
		onChangeReviewForm('media', imagesChosen)
	})


	const removeImageFromReview = (imageIndex) => {
		const imagesUpdated = curRevF?.currentReviewForm?.media;
		delete imagesUpdated.splice(imageIndex, 1);
		onChangeReviewForm('media', imagesUpdated)

	}

	return (
		<div className='sb-collection-pad sb-collection-form-ctn  sb-fs'>
			{
				curRevF?.currentReviewForm?.new !== true &&
				<div className='sb-collection-form-notice sb-fs'>
					<Notice
						icon='info'
						type='secondry'
						heading={ __( 'You are about to edit this review', 'reviews-feed' ) }
						text={ __( 'This review is displayed in', 'reviews-feed' ) + ' ' + curCl?.currentCollection?.used_in + ' ' + __( 'feeds. Editing it will change it on both those places. This action is irreversible.', 'reviews-feed' ) }
					/>
				</div>
			}
			<div className='sb-collection-form-row' data-rows='1'>
				<div className='sb-cl-form-img-ctn'>
					<div className='sb-cl-form-img'>
						{
							SbUtils.checkNotEmpty(curRevF?.currentReviewForm?.reviewer?.avatar) &&
								<img src={curRevF?.currentReviewForm?.reviewer?.avatar} alt={ __( 'User Avatar', 'reviews-feed' ) } />
						}
					</div>
					{
						!SbUtils.checkNotEmpty(curRevF?.currentReviewForm?.reviewer?.avatar) &&
						<Button
							type='secondary'
							size='small'
							icon='pen'
							iconSize='13'
							text={ __( 'Add Reviewer Image', 'reviews-feed' ) }
							onClick={ () => {
								openMediaLibrary()
							} }
						/>
					}
					{
						SbUtils.checkNotEmpty(curRevF?.currentReviewForm?.reviewer?.avatar) &&
						<>
							<Button
								type='secondary'
								size='small'
								icon='pen'
								iconSize='13'
								text={ __( 'Replace Image', 'reviews-feed' ) }
								onClick={ () => {
									openMediaLibrary()
								} }
								/>
							<Button
								type='destructive'
								size='small'
								iconSize='11'
								boxshadow={false}
								onlyicon={true}
								icon='trash'
								onClick={ () => {
									onChangeReviewForm('reviewer', {
										...curRevF?.currentReviewForm?.reviewer,
										avatar : ''
									})
								} }
							/>
						</>
					}
				</div>
			</div>
			<div className='sb-collection-form-row' data-rows='1'>
				<div className='sb-cl-form-stars-ctn sb-item-rating-ctn'>
					{
                        Array.from({ length: 5} , (ic, i) => {
                            const iconClass = curRevF?.currentReviewForm?.rating - i < 1 ? ' sb-item-rating-icon-dimmed' : '';
                            return (
								<span
									className='sb-item-collection-star'
									data-index={i + 1}
									key={i + 1}
									onClick={() => {
										onChangeReviewForm('rating', (i + 1))
									}}
								>
									{ SbUtils.printIcon( 'star', 'sb-item-rating-icon' + iconClass, i, 20 ) }
								</span>
							)
                        })
                    }
				</div>
			</div>
			<div className='sb-collection-form-row' data-rows='2'>
				<Input
					type='text'
					size='medium'
					label={ __( 'First Name', 'reviews-feed') }
					placeholder={ __( 'John', 'reviews-feed') }
					value={ curRevF?.currentReviewForm?.reviewer?.first_name }
                    onChange={ ( event ) => {
						onChangeReviewForm('reviewer', {
							...curRevF?.currentReviewForm?.reviewer,
							first_name : event.currentTarget.value
						})
                    } }
				/>
				<Input
					type='text'
					size='medium'
					label={ __( 'Last Name', 'reviews-feed') }
					placeholder={ __( 'Dow', 'reviews-feed') }
					value={ curRevF?.currentReviewForm?.reviewer?.last_name }
					onChange={ ( event ) => {
						onChangeReviewForm('reviewer', {
							...curRevF?.currentReviewForm?.reviewer,
							last_name : event.currentTarget.value
						})
                    } }
				/>
			</div>
			<div className='sb-collection-form-row' data-rows='1'>
				<Select
					size='medium'
					label={ __( 'Provider', 'reviews-feed') }
					value={ curRevF?.currentReviewForm?.provider?.name }
					onChange={(event) => {
						onChangeReviewForm('provider', {
							...curRevF?.currentReviewForm?.provider,
							name : event.currentTarget.value
						})
					}}
				>
					{
						providersOptions.map(pr=> {
							return (
								<option key={pr} value={pr}>{pr}</option>
							)
						})
					}
				</Select>
			</div>
			<div className='sb-collection-form-row' data-rows='1'>
				<Input
					type='text'
					size='medium'
					label={ __( 'Review Title', 'reviews-feed') }
					placeholder={ __( 'The review title goes here', 'reviews-feed') }
					value={ curRevF?.currentReviewForm?.title }
					onChange={ ( event ) => {
						onChangeReviewForm('title', event.currentTarget.value)
                    } }
				/>
			</div>
			<div className='sb-collection-form-row' data-rows='1'>
				<Textarea
					type='text'
					size='medium'
					label={ __( 'Review Content', 'reviews-feed') }
					placeholder={ __( 'The content title goes here', 'reviews-feed') }
					rows='10'
					value={ curRevF?.currentReviewForm?.text }
					onChange={ ( event ) => {
						onChangeReviewForm('text', event.currentTarget.value)
                    } }
				/>
			</div>
			<div className='sb-collection-form-row' data-rows='2'>
				<Input
					type='url'
					size='medium'
					label={ __( 'Link', 'reviews-feed') }
					placeholder={ __( 'Link to the review (Optional)', 'reviews-feed') }
					value={ curRevF?.currentReviewForm?.source?.url }
					onChange={ ( event ) => {
						onChangeReviewForm('source', {
							...curRevF?.currentReviewForm?.source,
							url : event.currentTarget.value
						})
                    } }
				/>
				<Input
					type='datetime-local'
					size='medium'
					label={ __( 'Posted on', 'reviews-feed') }
					placeholder={ __( 'Select Date', 'reviews-feed') }
					value={ SbUtils.converDate(curRevF?.currentReviewForm?.time) }
					onChange={ ( event ) => {
						onChangeReviewForm('time', event.currentTarget.value)
                    } }
				/>
			</div>
			<div className='sb-collection-form-imgs sb-fs'>
				<div
					className='sb-collection-form-im-chooser sb-svg-p'
					onClick={() => {
						openMediaLibraryReviewImage()
					}}
				>
					{ SbUtils.printIcon('plus', false, false, 20) }
				</div>
				{
					curRevF?.currentReviewForm?.media !== undefined && curRevF?.currentReviewForm?.media.length > 0 &&
					curRevF?.currentReviewForm?.media.map((img, indImg) => {
						return (
							<div
								key={indImg}
								data-em={img}
								className='sb-collection-form-im'
								style={{backgroundImage : 'url('+ img.url +')'}}
							>
								<div
									className='sb-collection-form-im-delete sb-tr-2 sb-svg-p'
									onClick={() => {
										removeImageFromReview(indImg)
									}}
								>
									{ SbUtils.printIcon('close', false, false, 12) }
								</div>
							</div>
						)
					})
				}
			</div>
		</div>
	)
}

export default CollectionReviewsForm;

