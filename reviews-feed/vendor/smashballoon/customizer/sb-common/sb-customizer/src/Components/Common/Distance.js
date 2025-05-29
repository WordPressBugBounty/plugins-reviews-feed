import { useState, useRef } from 'react'
import SbUtils from '../../Utils/SbUtils'
import Draggable from 'react-draggable';

const Distance = ( props ) => {
    const nodeRef = useRef(null);

    const slug = 'sb-distance',
            [ activeSide, setActiveSide ] = useState( 'none' ),
            actionsList =  [ 'onClick' ],
            classesList =  [ 'distancetype' ],
            sidesList = [ 'top', 'right', 'bottom', 'left'],
            elementSides = props?.sides !== undefined ? Object.keys( props.sides ) : sidesList,
            defaultValues = {
                top : '',
                right : '',
                bottom : '',
                left : '',
                ...props?.value
            },
            min = props.min ? props.min : 0 ,
            max =  props.max ? props.max : 100;

    const [ distanceValues,  setDistanceValues ] = useState( defaultValues );

    const changeDistanceValue = ( value, type ) => {
        const newDistanceValues = {
            ...distanceValues,
            [ type ] : value
        };
        setDistanceValues( newDistanceValues );
        props.onChange( newDistanceValues );
    }


    return (
        <div
            className={'sb-distance-ctn sb-fs ' + SbUtils.getClassNames( props, slug, classesList)}
            { ...SbUtils.getElementActions( props, actionsList ) }
            data-side-focused={activeSide}
            data-disabled={ props?.disabled }
            onClick={ () => {
                if( props?.onParentClick !== undefined){
                    props.onParentClick()
                }
            } }
        >
            <div className='sb-distance-icons'>
                {
                    props?.disabled === true &&
                    <div className='sb-element-overlay'></div>
                }
                {
                    sidesList.map( ( side, sideInd ) => {
                        return (
                            <div
                                className='sb-distance-side-icon-ctn'
                                data-side={ side }
                                key={ sideInd }
                                data-active={activeSide === side}
                                onMouseDown={ () => {
                                    if( elementSides.includes( side )){
                                        setTimeout(() => {
                                           setActiveSide( side )
                                        }, 100);
                                    }
                                }}
                            >
                                <div
                                    className='sb-distance-side-icon'
                                    data-side={ side }
                                    data-disabled={ !elementSides.includes( side ) }
                                >
                                </div>
                                <Draggable
                                    nodeRef={nodeRef}
                                    axis={side === 'left' || side === 'right' ? 'x' : 'y'}
                                    position={{ x: 0, y:0 }}
                                    onStop={ () => {
                                        setActiveSide( 'none' )
                                    }}
                                    onDrag={ (e, data) => {
                                        let valueDragged = side === 'left' || side === 'right' ? data.x : -data.y,
                                            initialDragged = side === 'left' || side === 'right' ? data.lastX : -data.lastY,
                                            newValue = parseInt(distanceValues[side] !== '' ? distanceValues[side] :  0 ) + parseInt( (valueDragged - initialDragged < 0 ) ? -1 : 1);
                                            newValue = ( newValue > max ? max : newValue);
                                            newValue = ( newValue < min ? min : newValue);
                                        changeDistanceValue( newValue, side )
                                    }}
                                    onStart={ () => {
                                        if( elementSides.includes( side )  ){
                                            setActiveSide( side )
                                        }
                                    }}
                                >
                                    <div
                                        className='sb-distance-side-draggable' ref={nodeRef}
                                    ></div>
                                </Draggable>
                            </div>
                        )
                    })
                }
            </div>

            <div className='sb-distance-inputs'>
                {
                    sidesList.map( ( side, sideInd ) => {
                        if( elementSides.includes( side ) ){
                            return (
                                <div
                                    className='sb-distance-input-ctn'
                                    data-side={ side }
                                    key={ sideInd }>
                                        <input
                                            type='number'
                                            data-side={ side }
                                            value={ distanceValues[side] }
                                            min={ min }
                                            max={ max }
                                            disabled={ props?.disabled === true}
                                            onFocus={ () => {
                                                setActiveSide( side )
                                            }}
                                            onBlur={ () => {
                                                setActiveSide( 'none' )
                                            }}
                                            onChange={ ( event ) => {
                                                if( props?.disabled !== true ){
                                                    changeDistanceValue( event.currentTarget.value, side )
                                                }
                                            }}
                                        />
                                        <span>px</span>
                                </div>
                            )
                        }
                        return null;
                    })
                }
            </div>
        </div>
    )
}

export default Distance;